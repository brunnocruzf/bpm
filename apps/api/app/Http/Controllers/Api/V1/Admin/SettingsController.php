<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\AuditDiff;
use App\Authorization\Permissions;
use App\Branding\BrandingAssetService;
use App\Http\Controllers\Api\V1\ApiController;
use App\Identity\DirectorySyncService;
use App\Identity\EntraLoginValidator;
use App\Mail\MailException;
use App\Mail\MailProviderFactory;
use App\Models\AppSetting;
use App\Models\BrandingAsset;
use App\Models\NotificationMessage;
use App\Notifications\EmailLayout;
use App\Notifications\EmailLayoutRenderer;
use App\Notifications\Notifier;
use App\Reports\CarboneReportRenderer;
use App\Settings\SettingsRepository;
use App\Settings\SettingType;
use App\Settings\Types\AttachmentStorageSettings;
use App\Settings\Types\AuditRetentionSettings;
use App\Settings\Types\BrandingSettings;
use App\Settings\Types\CategoriesSettings;
use App\Settings\Types\DirectorySyncSettings;
use App\Settings\Types\EmailSettings;
use App\Settings\Types\EntraAuthSettings;
use App\Settings\Types\ReportSettings;
use App\Storage\StorageProbe;
use App\Storage\StorageTargetService;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Configurações → (organização, Entra login, Entra diretório, e-mail, auditoria, relatórios).
 * Toda rota exige settings.manage (Gate). Segredos são somente escrita: nunca retornam.
 */
class SettingsController extends ApiController
{
    /** @var array<string, class-string<SettingType>> */
    private const TYPES = [
        'branding' => BrandingSettings::class,
        'entra/auth' => EntraAuthSettings::class,
        'entra/directory-sync' => DirectorySyncSettings::class,
        'email' => EmailSettings::class,
        'audit-retention' => AuditRetentionSettings::class,
        'reports' => ReportSettings::class,
        'attachments-storage' => AttachmentStorageSettings::class,
        'categories' => CategoriesSettings::class,
    ];

    public function __construct(private readonly SettingsRepository $settings) {}

    private function authorizeSettings(): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
    }

    private function type(string $slug): SettingType
    {
        $class = self::TYPES[$slug] ?? throw ApiProblem::notFound('Configuração desconhecida.');

        return app($class);
    }

    private function show(SettingType $type): JsonResponse
    {
        return response()->json($type->describe($this->settings->get($type->key())));
    }

    public function index(): JsonResponse
    {
        $this->authorizeSettings();
        $out = [];
        foreach (self::TYPES as $slug => $class) {
            $type = app($class);
            $s = $this->settings->get($type->key());
            $d = $type->describe($s);
            unset($d['value']);
            $out[] = ['slug' => $slug, ...$d];
        }

        return response()->json(['data' => $out]);
    }

    public function get(string $slug): JsonResponse
    {
        $this->authorizeSettings();

        return $this->show($this->type($slug));
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $this->authorizeSettings();
        $type = $this->type($slug);
        $input = $request->json()->all();
        if (strlen((string) $request->getContent()) > 64 * 1024) {
            throw ApiProblem::validation(['body' => ['Payload inválido.']]);
        }
        $this->settings->update($type, $input, $this->user());
        if (array_filter([$input['clientSecret'] ?? null, $input['certificatePem'] ?? null, $input['smtp']['password'] ?? null, $input['m365']['clientSecret'] ?? null, $input['m365']['certificatePem'] ?? null, $input['azure']['accountKey'] ?? null])) {
            AuditLogger::record('settings.secret_updated', 'app_setting', $type->key(), ['setting' => $type->key()], null, $this->user(), context: ['object_name' => $type->label()]);
        }

        return $this->show($type);
    }

    // ------------------------------------------------------------------ identidade visual

    public function uploadAsset(Request $request, BrandingAssetService $assets): JsonResponse
    {
        $this->authorizeSettings();
        $request->validate(['kind' => ['required', 'in:logo_main,logo_compact,favicon'], 'file' => ['required', 'file']]);
        $asset = $assets->upload($request->file('file'), (string) $request->input('kind'), $this->user());

        return response()->json(['id' => $asset->id, 'kind' => $asset->kind, 'width' => $asset->width, 'height' => $asset->height, 'type' => $asset->content_type, 'size' => $asset->size], 201);
    }

    public function previewAsset(string $id, BrandingAssetService $assets): Response
    {
        $this->authorizeSettings();
        $asset = BrandingAsset::find($id) ?? throw ApiProblem::notFound();

        return response((string) $assets->contents($asset), 200, ['Content-Type' => $asset->content_type, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'"]);
    }

    public function resetBranding(): JsonResponse
    {
        $this->authorizeSettings();
        $type = app(BrandingSettings::class);
        $before = $this->settings->value($type);
        $this->settings->update($type, $type->defaults(), $this->user());
        AuditLogger::record('branding.reset', 'app_setting', BrandingSettings::KEY, [], null, $this->user(), diff: AuditDiff::between($before, $type->defaults()));

        return $this->show($type);
    }

    // ------------------------------------------------------------------ Entra login

    public function validateEntraAuth(EntraLoginValidator $validator): JsonResponse
    {
        $this->authorizeSettings();
        $type = app(EntraAuthSettings::class);
        $setting = $this->settings->get($type->key());
        $result = $validator->discover($this->settings->value($type));
        $this->settings->recordValidation($type, (bool) $result['ok'], $result, $this->user(), $setting->version);

        return $this->show($type);
    }

    public function tokenTest(Request $request, EntraLoginValidator $validator): JsonResponse
    {
        $this->authorizeSettings();
        $data = $request->validate(['accessToken' => ['required', 'string', 'max:20000']]);
        $type = app(EntraAuthSettings::class);
        $setting = $this->settings->get($type->key());
        $discovery = $setting->last_validation && ! empty($setting->last_validation['tenantGuid']) ? $setting->last_validation : null;
        $result = $validator->testToken($this->settings->value($type), (string) $data['accessToken'], $discovery);
        $this->settings->recordValidation($type, (bool) $result['ok'], $result, $this->user(), $setting->version);

        return $this->show($type);
    }

    public function activateEntraAuth(): JsonResponse
    {
        $this->authorizeSettings();
        $type = app(EntraAuthSettings::class);
        $setting = $this->settings->get($type->key());
        if ($setting->status !== AppSetting::CONFIGURED || ($setting->last_validation['code'] ?? null) !== 'token_ok' || ($setting->last_validation['version'] ?? null) !== $setting->version) {
            throw ApiProblem::conflict('Ative somente após descoberta OIDC e teste de login/token bem-sucedidos nesta versão da configuração.', 'validation_required');
        }
        $this->settings->activate($type, $this->user());

        return $this->show($type);
    }

    public function deactivateEntraAuth(): JsonResponse
    {
        $this->authorizeSettings();
        $type = app(EntraAuthSettings::class);
        $this->settings->deactivate($type, $this->user());

        return $this->show($type);
    }

    // ------------------------------------------------------------------ Entra diretório

    public function testDirectory(DirectorySyncService $sync): JsonResponse
    {
        $this->authorizeSettings();
        $type = app(DirectorySyncSettings::class);
        $setting = $this->settings->get($type->key());
        $cfg = $this->settings->value($type);
        if ($missing = $type->missing($cfg)) {
            throw ApiProblem::validation(['config' => ['Configuração incompleta: '.implode(', ', $missing).'.']]);
        }
        $result = $sync->testConnection($cfg);
        $this->settings->recordValidation($type, (bool) $result['ok'], $result, $this->user(), $setting->version);

        return $this->show($type);
    }

    // ------------------------------------------------------------------ armazenamento de anexos

    public function testAttachmentStorage(StorageProbe $probe): JsonResponse
    {
        $this->authorizeSettings();
        $type = app(AttachmentStorageSettings::class);
        $setting = $this->settings->get($type->key());
        $cfg = $this->settings->value($type);
        if ($cfg['provider'] !== 'azure_blob') {
            throw ApiProblem::validation(['provider' => ['Selecione o Azure Blob Storage para testar.']]);
        }
        if ($missing = $type->missing($cfg)) {
            throw ApiProblem::validation(['azure' => ['Configuração incompleta: '.implode(', ', $missing).'.']]);
        }
        $result = $probe->run($cfg);
        $this->settings->recordValidation($type, (bool) $result['ok'], $result, $this->user(), $setting->version);

        return $this->show($type);
    }

    public function activateAttachmentStorage(StorageTargetService $targets): JsonResponse
    {
        $this->authorizeSettings();
        $type = app(AttachmentStorageSettings::class);
        $setting = $this->settings->get($type->key());
        if ($setting->status !== AppSetting::CONFIGURED || ($setting->last_validation['code'] ?? null) !== 'storage_ok' || ($setting->last_validation['version'] ?? null) !== $setting->version) {
            throw ApiProblem::conflict('Ative somente após um teste de gravação, leitura e exclusão bem-sucedido nesta versão da configuração.', 'validation_required');
        }
        $targets->activate($this->settings->value($type), $setting->version, $this->user());
        $this->settings->activate($type, $this->user());

        return $this->show($type);
    }

    public function deactivateAttachmentStorage(StorageTargetService $targets): JsonResponse
    {
        $this->authorizeSettings();
        $type = app(AttachmentStorageSettings::class);
        $targets->deactivate();
        if (AppSetting::where('key', $type->key())->exists()) {
            $this->settings->deactivate($type, $this->user());
        }

        return $this->show($type);
    }

    public function attachmentStorageUsage(StorageTargetService $targets): JsonResponse
    {
        $this->authorizeSettings();

        return response()->json(['data' => $targets->usage()]);
    }

    // ------------------------------------------------------------------ e-mail

    public function testEmailConnection(MailProviderFactory $factory): JsonResponse
    {
        $this->authorizeSettings();
        $type = app(EmailSettings::class);
        $setting = $this->settings->get($type->key());
        $cfg = $this->settings->value($type);
        if ($cfg['provider'] === 'disabled') {
            throw ApiProblem::validation(['provider' => ['Provedor desabilitado.']]);
        }
        if ($missing = $type->missing($cfg)) {
            throw ApiProblem::validation(['provider' => ['Configuração incompleta: '.implode(', ', $missing).'.']]);
        }
        $started = microtime(true);
        try {
            $checks = $factory->make($cfg)->test();
        } catch (MailException $e) {
            $checks = [['name' => 'Provedor', 'ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage()]];
        } catch (Throwable) {
            $checks = [['name' => 'Provedor', 'ok' => false, 'code' => 'provider_error', 'message' => 'Falha inesperada no teste.']];
        }
        $ok = $checks !== [] && collect($checks)->every(fn ($c) => $c['ok']);
        $failed = collect($checks)->firstWhere('ok', false);
        $this->settings->recordValidation($type, $ok, [
            'step' => 'connection', 'code' => $ok ? 'connection_ok' : ($failed['code'] ?? 'failed'), 'checks' => $checks,
            'message' => $ok ? 'Conexão verificada. Envie um e-mail de teste para concluir.' : ($failed['message'] ?? 'Falha'),
            'durationMs' => (int) round((microtime(true) - $started) * 1000), 'provider' => $cfg['provider'], 'complete' => false,
            'detail' => $ok ? 'Conexão OK; falta o e-mail de teste.' : null,
        ], $this->user(), $setting->version);

        return $this->show($type);
    }

    public function sendTestEmail(Request $request, Notifier $notifier): JsonResponse
    {
        $this->authorizeSettings();
        $data = $request->validate(['to' => ['required', 'email:rfc', 'max:200']]);
        $type = app(EmailSettings::class);
        $cfg = $this->settings->value($type);
        if ($cfg['provider'] === 'disabled') {
            throw ApiProblem::validation(['provider' => ['Provedor desabilitado.']]);
        }
        $brand = $this->settings->value(app(BrandingSettings::class));
        $message = $notifier->testEmail((string) $data['to'], $this->user(), "[{$brand['orgName']}] E-mail de teste do BPM",
            "Este é um e-mail de teste enviado por {$this->user()->name} em Configurações → E-mail e notificações.\n\nProvedor: ".EmailSettings::PROVIDERS[$cfg['provider']].".\nSe você recebeu esta mensagem, o envio está funcionando.");

        return response()->json($this->testView($message), 202);
    }

    public function previewEmailTemplate(Request $request, EmailLayoutRenderer $renderer): JsonResponse
    {
        $this->authorizeSettings();
        if (strlen((string) $request->getContent()) > 16 * 1024) {
            throw ApiProblem::validation(['body' => ['Payload de prévia muito grande.']]);
        }
        $data = $request->validate([
            'sample' => ['required', 'string', 'in:task.assigned,stage.completed,instance.ended,identity.invitation,identity.password_reset,admin.alert,email.test'],
            'layout' => ['sometimes', 'array'],
            'layout.accentColor' => ['sometimes', 'string', 'max:7'],
            'layout.headerTitle' => ['sometimes', 'string', 'max:80'],
            'layout.footerText' => ['sometimes', 'string', 'max:240'],
        ]);
        $settings = $this->settings->value(app(EmailSettings::class));
        $layout = EmailLayout::normalize((array) ($data['layout'] ?? []), (array) ($settings['layout'] ?? EmailLayout::defaults()));
        $branding = $this->settings->value(app(BrandingSettings::class));

        return response()->json($renderer->preview((string) $data['sample'], $layout, $branding));
    }

    public function testEmailStatus(string $id): JsonResponse
    {
        $this->authorizeSettings();
        $message = NotificationMessage::where('event', 'email.test')->find($id) ?? throw ApiProblem::notFound();
        $type = app(EmailSettings::class);
        $setting = $this->settings->get($type->key());
        // conclui a validação quando o teste termina (uma única vez por mensagem)
        if (in_array($message->state, [NotificationMessage::SENT, NotificationMessage::FAILED], true) && ($setting->last_validation['testMessageId'] ?? null) !== $message->id) {
            $ok = $message->state === NotificationMessage::SENT;
            $this->settings->recordValidation($type, $ok, [
                'step' => 'test_email', 'code' => $ok ? 'test_email_sent' : ($message->error_code ?? 'failed'), 'testMessageId' => $message->id,
                'message' => $ok ? 'E-mail de teste aceito pelo provedor.' : 'E-mail de teste falhou: '.($message->error_code ?? 'erro'),
                'provider' => $message->provider, 'complete' => true, 'detail' => $ok ? 'Conexão e envio de teste verificados.' : null,
                'durationMs' => $message->sent_at && $message->created_at ? (int) $message->created_at->diffInMilliseconds($message->sent_at) : null,
            ], $this->user(), $setting->version);
        }

        return response()->json($this->testView($message));
    }

    /** @return array<string,mixed> */
    private function testView(NotificationMessage $m): array
    {
        return [
            'id' => $m->id, 'state' => $m->state, 'provider' => $m->provider, 'providerMessageId' => $m->provider_message_id,
            'errorCode' => $m->error_code, 'error' => $m->last_error, 'attempts' => $m->attempts,
            'createdAt' => $m->created_at?->toIso8601String(), 'sentAt' => $m->sent_at?->toIso8601String(),
            'durationMs' => $m->sent_at && $m->created_at ? (int) $m->created_at->diffInMilliseconds($m->sent_at) : null,
        ];
    }

    // ------------------------------------------------------------------ relatórios

    public function carboneHealth(): JsonResponse
    {
        $this->authorizeSettings();
        $type = app(ReportSettings::class);
        $setting = $this->settings->get($type->key());
        $health = CarboneReportRenderer::health();
        $this->settings->recordValidation($type, $health['ok'], ['step' => 'carbone', ...$health, 'complete' => true], $this->user(), $setting->version);

        return $this->show($type);
    }
}
