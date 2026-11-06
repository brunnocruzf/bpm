<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Authorization\Permissions;
use App\Backup\BackupScheduler;
use App\Backup\RecoveryKey;
use App\Backup\RuntimeState;
use App\Engine\FlowableRestClient;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\BackupCatalogEntry;
use App\Models\BackupJob;
use App\Models\EngineCommand;
use App\Models\NotificationMessage;
use App\Models\ProcessInstance;
use App\Models\ProcessStageVersion;
use App\Settings\SettingsRepository;
use App\Settings\Types\BackupSettings;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Backup e recuperação (settings.manage). Só registra pedidos de tipo fixo: quem executa é o backup-agent, e esta API não
 * tem acesso ao Docker nem à senha de superusuário do banco.
 */
class BackupController extends ApiController
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly RuntimeState $state,
        private readonly RecoveryKey $keys,
    ) {}

    private function authorizeBackups(): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
    }

    public function index(): JsonResponse
    {
        $this->authorizeBackups();

        return response()->json(['data' => BackupCatalogEntry::orderByDesc('backup_at')->limit(200)->get()->map->present()]);
    }

    public function status(BackupScheduler $scheduler): JsonResponse
    {
        $this->authorizeBackups();
        $cfg = $this->config();
        $last = BackupCatalogEntry::orderByDesc('backup_at')->first();
        $lastFull = BackupCatalogEntry::where('scope', BackupSettings::FULL)->max('backup_at');
        $lastTest = BackupCatalogEntry::whereNotNull('verified_at')->orderByDesc('verified_at')->first();
        $active = BackupJob::whereIn('status', [BackupJob::PENDING, BackupJob::RUNNING])->orderBy('created_at')->first();
        $operation = (array) ($this->state->read()['operation'] ?? []);
        $fullLimit = now()->subDays((int) config('bpm.backup.full_backup_warning_days'));
        $testLimit = now()->subDays((int) config('bpm.backup.restore_test_warning_days'));

        return response()->json([
            'mode' => $this->state->mode(),
            'organization' => (string) config('bpm.organization.name'),
            'agent' => ['online' => $this->state->agentOnline(), 'lastBeatAt' => $this->state->lastBeat()?->toIso8601String()],
            'lastBackup' => $last?->present(),
            'nextRunAt' => $scheduler->nextRun()?->toIso8601String(),
            'recoveryKey' => $cfg['recoveryKey'] ? array_diff_key($cfg['recoveryKey'], ['publicKey' => 1]) : null,
            'lastRestoreTest' => $lastTest ? ['at' => $lastTest->verified_at?->toIso8601String(), 'backupId' => $lastTest->id, 'ok' => (bool) ($lastTest->verification['ok'] ?? false)] : null,
            'warnings' => array_values(array_filter([
                ! $lastFull || $fullLimit->gt($lastFull) ? 'no_recent_full_backup' : null,
                ! $lastTest || $testLimit->gt($lastTest->verified_at) ? 'no_recent_restore_test' : null,
            ])),
            'activeJob' => $active ? [...$active->present(), 'stage' => $operation['stage'] ?? $active->stage, 'percent' => $operation['percent'] ?? $active->percent] : null,
        ])->header('Cache-Control', 'no-store');
    }

    public function jobs(): JsonResponse
    {
        $this->authorizeBackups();

        return response()->json(['data' => BackupJob::orderByDesc('created_at')->limit(50)->get()->map->present()]);
    }

    public function job(string $id): JsonResponse
    {
        $this->authorizeBackups();

        return response()->json((BackupJob::find($id) ?? throw ApiProblem::notFound())->present());
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeBackups();
        $this->assertReady();
        if (empty($this->config()['recoveryKey'])) {
            throw ApiProblem::conflict('Gere e confirme a chave de recuperação antes de fazer backups.', 'recovery_key_missing');
        }
        $scope = (string) $request->input('scope');
        if (! array_key_exists($scope, BackupSettings::SCOPES)) {
            throw ApiProblem::validation(['scope' => ['Escolha o que será copiado.']]);
        }
        $job = $this->enqueue(BackupJob::BACKUP, ['scope' => $scope, 'azure' => $request->boolean('azure'), 'verify' => $request->boolean('verify')]);
        AuditLogger::record('backup.requested', 'backup_job', $job->id, ['scope' => $scope], null, $this->user());

        return response()->json($job->present(), 202);
    }

    public function syncCatalog(): JsonResponse
    {
        $this->authorizeBackups();
        $this->assertReady();

        return response()->json($this->enqueue(BackupJob::CATALOG_SYNC)->present(), 202);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->authorizeBackups();
        $this->assertReady();
        $entry = $this->entry($id);
        if (BackupCatalogEntry::orderByDesc('backup_at')->value('id') === $entry->id) {
            throw ApiProblem::conflict('O backup mais recente não pode ser apagado.', 'backup_is_latest');
        }

        return response()->json($this->enqueue(BackupJob::DELETE, [], $entry->id)->present(), 202);
    }

    public function copyToAzure(string $id): JsonResponse
    {
        $this->authorizeBackups();
        $this->assertReady();
        $entry = $this->entry($id);
        if (! $entry->in_local) {
            throw ApiProblem::conflict('Este backup não tem mais cópia no servidor.', 'backup_not_local');
        }

        return response()->json($this->enqueue(BackupJob::COPY_AZURE, [], $entry->id)->present(), 202);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        $this->authorizeBackups();
        $entry = $this->entry($id);
        $errors = [];
        if (mb_strtoupper(trim((string) $request->input('confirmation'))) !== mb_strtoupper((string) config('bpm.organization.name'))) {
            $errors['confirmation'][] = 'Digite o nome da organização para confirmar.';
        }
        $justification = trim(strip_tags((string) $request->input('justification')));
        if (mb_strlen($justification) < 10) {
            $errors['justification'][] = 'Explique o motivo em pelo menos 10 caracteres.';
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }
        $key = $this->matchingKey($request, $entry);
        $this->assertReady();
        $job = $this->enqueue(BackupJob::RESTORE, ['justification' => mb_substr($justification, 0, 500)], $entry->id, $this->seal($key));
        AuditLogger::record('backup.restore_requested', 'backup_catalog', $entry->id, ['backupAt' => $entry->backup_at->toIso8601String(), 'justification' => $justification], null, $this->user());

        return response()->json($job->present(), 202);
    }

    public function restoreTest(Request $request, string $id): JsonResponse
    {
        $this->authorizeBackups();
        $entry = $this->entry($id);
        $key = $this->matchingKey($request, $entry);
        $this->assertReady();

        return response()->json($this->enqueue(BackupJob::RESTORE_TEST, [], $entry->id, $this->seal($key))->present(), 202);
    }

    /** A chave só existe nesta resposta: o servidor guarda a parte pública depois da confirmação. */
    public function generateKey(): JsonResponse
    {
        $this->authorizeBackups();

        return response()->json($this->keys->generate())->header('Cache-Control', 'no-store');
    }

    public function confirmKey(Request $request): JsonResponse
    {
        $this->authorizeBackups();
        $public = $this->keys->publicOf((string) $request->input('recoveryKey'));
        $fingerprint = RecoveryKey::fingerprint($public);
        $user = $this->user();
        $type = app(BackupSettings::class)->withRecoveryKey(['publicKey' => $public, 'fingerprint' => $fingerprint, 'confirmedAt' => now('UTC')->toIso8601String(), 'confirmedBy' => $user->name]);
        $this->settings->update($type, [], $user);
        AuditLogger::record('backup.key_generated', 'app_setting', BackupSettings::KEY, ['fingerprint' => $fingerprint], null, $user);

        return response()->json(['fingerprint' => $fingerprint]);
    }

    public function safeMode(FlowableRestClient $engine): JsonResponse
    {
        $this->authorizeBackups();
        $restore = (array) ($this->state->read()['restore'] ?? []);

        return response()->json([
            'active' => $this->state->mode() === RuntimeState::SAFE,
            'restoredTo' => $restore['restoredTo'] ?? null,
            'backupId' => $restore['backupId'] ?? null,
            'canUndo' => (bool) ($restore['canUndo'] ?? true),
            'auditEvents' => $restore['auditEvents'] ?? 0,
            'pendingCommands' => EngineCommand::whereNotIn('state', [EngineCommand::CONFIRMED, EngineCommand::FAILED])->count(),
            'pendingEmails' => NotificationMessage::whereIn('state', [NotificationMessage::QUEUED, NotificationMessage::RETRYING])->count(),
            'integrations' => $this->pendingIntegrations($engine),
        ])->header('Cache-Control', 'no-store');
    }

    public function release(Request $request): JsonResponse
    {
        $this->authorizeBackups();
        $this->assertSafeMode();
        $notifications = (string) $request->input('notifications');
        if (! in_array($notifications, ['send', 'discard'], true)) {
            throw ApiProblem::validation(['notifications' => ['Escolha enviar ou descartar os e-mails pendentes.']]);
        }

        return response()->json($this->enqueue(BackupJob::RELEASE, ['notifications' => $notifications])->present(), 202);
    }

    public function undo(): JsonResponse
    {
        $this->authorizeBackups();
        $this->assertSafeMode();
        if (! ($this->state->read()['restore']['canUndo'] ?? true)) {
            throw ApiProblem::conflict('Uma recuperação em servidor novo não pode ser desfeita.', 'undo_unavailable');
        }

        return response()->json($this->enqueue(BackupJob::UNDO_RESTORE)->present(), 202);
    }

    /** @return array<string,mixed> */
    private function config(): array
    {
        return $this->settings->value(app(BackupSettings::class));
    }

    private function entry(string $id): BackupCatalogEntry
    {
        return BackupCatalogEntry::find($id) ?? throw ApiProblem::notFound('Backup não encontrado.');
    }

    /** Devolve a chave informada, conferindo antes se é a que abre este backup. */
    private function matchingKey(Request $request, BackupCatalogEntry $entry): string
    {
        $key = trim((string) $request->input('recoveryKey'));
        if ($this->keys->publicOf($key) !== ($entry->manifest['encryption']['recipient'] ?? null)) {
            throw ApiProblem::validation(['recoveryKey' => ["Esta chave não abre este backup. Ele foi criado com a chave {$entry->key_fingerprint}."]]);
        }

        return $key;
    }

    /** Cifra para a chave efêmera do agente: a chave de recuperação não fica aberta nem no banco. */
    private function seal(string $key): string
    {
        $public = base64_decode((string) $this->state->sealKey(), true);
        if ($public === false || $public === '') {
            throw ApiProblem::conflict('O agente de backup ainda não está pronto. Tente de novo em instantes.', 'backup_agent_offline');
        }

        return base64_encode(sodium_crypto_box_seal($key, $public));
    }

    private function assertReady(): void
    {
        if ($this->state->mode() !== RuntimeState::NORMAL) {
            throw ApiProblem::conflict('Conclua a revisão da restauração antes de iniciar outra operação.', 'backup_not_available');
        }
        if (! $this->state->agentOnline()) {
            throw ApiProblem::conflict('O agente de backup não está respondendo. Confira o serviço backup-agent no servidor.', 'backup_agent_offline');
        }
        if (BackupJob::whereIn('status', [BackupJob::PENDING, BackupJob::RUNNING])->exists()) {
            throw ApiProblem::conflict('Já existe uma operação de backup em andamento. Aguarde o fim dela.', 'backup_busy');
        }
    }

    private function assertSafeMode(): void
    {
        if ($this->state->mode() !== RuntimeState::SAFE) {
            throw ApiProblem::conflict('Não há restauração aguardando revisão.', 'not_in_safe_mode');
        }
        if (! $this->state->agentOnline()) {
            throw ApiProblem::conflict('O agente de backup não está respondendo. Confira o serviço backup-agent no servidor.', 'backup_agent_offline');
        }
    }

    /** @param array<string,mixed> $params */
    private function enqueue(string $kind, array $params = [], ?string $backupId = null, ?string $sealed = null): BackupJob
    {
        $user = $this->user();

        return BackupJob::create(['kind' => $kind, 'params' => $params, 'backup_id' => $backupId, 'sealed_secret' => $sealed, 'requested_by' => $user->id, 'requested_by_name' => $user->name]);
    }

    /**
     * Service tasks que vão rodar depois da liberação: podem já ter escrito no sistema de destino antes da restauração.
     *
     * @return list<array{instance:?string, stage:?string}>
     */
    private function pendingIntegrations(FlowableRestClient $engine): array
    {
        $out = [];
        foreach ($engine->externalJobs((string) config('bpm.engine.external_worker_topic')) as $job) {
            $instance = ProcessInstance::where('engine_process_instance_id', $job['processInstanceId'] ?? '')->first();
            $stage = $instance ? ProcessStageVersion::where('definition_version_id', $instance->definition_version_id)->where('element_id', $job['elementId'] ?? '')->first() : null;
            $out[] = ['instance' => $instance?->number, 'stage' => $stage?->name];
        }

        return $out;
    }
}
