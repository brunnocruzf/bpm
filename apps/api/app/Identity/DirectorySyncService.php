<?php

namespace App\Identity;

use App\Audit\AuditContext;
use App\Audit\AuditDiff;
use App\Identity\Graph\GraphClient;
use App\Identity\Graph\GraphException;
use App\Identity\Graph\HttpGraphClient;
use App\Identity\Graph\MockGraphClient;
use App\Jobs\RunDirectorySync;
use App\Models\BpmGroup;
use App\Models\DirectoryGroup;
use App\Models\DirectoryMembership;
use App\Models\DirectoryRoleMapping;
use App\Models\DirectorySyncCursor;
use App\Models\DirectorySyncRun;
use App\Models\User;
use App\Notifications\Notifier;
use App\Settings\SettingsRepository;
use App\Settings\Types\DirectorySyncSettings;
use App\Support\AuditLogger;
use App\Support\Correlation;
use App\Support\Masker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Serviço de domínio da sincronização do diretório Entra (Graph v1.0, app-only, leitura).
 *
 * Garantias:
 *  - escopo = membros diretos dos grupos selecionados ou, com escopo "all" (decisão explícita do
 *    administrador), todos os grupos e usuários do tenant; chave estável tenant + oid (nunca e-mail/UPN);
 *  - @odata.nextLink persiste só durante a paginação (retomada); @odata.deltaLink só após a rodada
 *    inteira sem erros — falha parcial nunca avança o checkpoint;
 *  - nada é apagado: exclusão/desativação no diretório desativa login/atribuição futura e preserva
 *    autoria/histórico; desativação e remoção por ausência têm trava de percentual (bloqueio);
 *  - grupo importado não concede nada sem mapeamento explícito; papel privilegiado exige mapeamento
 *    marcado como privilegiado; dados do browser nunca escolhem grupos/papéis;
 *  - prévia (dry run) executa a mesma lógica dentro de transação desfeita ao final.
 */
class DirectorySyncService
{
    private const LOCK = 'bpm:directory-sync';

    /** @var array<string,mixed> */
    private array $s = [];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly EntraAppTokenProvider $tokens,
        private readonly Notifier $notifier,
    ) {}

    public function type(): DirectorySyncSettings
    {
        return app(DirectorySyncSettings::class);
    }

    /** @return array<string,mixed> */
    public function config(): array
    {
        return $this->settings->value($this->type());
    }

    /** Solicita execução (idempotente: devolve a execução ativa, se houver). */
    public function request(string $mode, string $trigger, ?User $by): DirectorySyncRun
    {
        $run = DB::transaction(function () use ($mode, $trigger, $by) {
            $active = DirectorySyncRun::whereIn('status', [DirectorySyncRun::QUEUED, DirectorySyncRun::RUNNING])
                ->where('created_at', '>', now()->subHours(2))->lockForUpdate()->first();
            if ($active) {
                return $active;
            }
            $cfg = $this->config();
            $run = DirectorySyncRun::create([
                'mode' => in_array($mode, ['full', 'delta', 'preview'], true) ? $mode : 'delta',
                'trigger' => $trigger,
                'transport' => $cfg['transport'],
                'requested_by' => $by?->id,
                'requested_by_name' => $by->name ?? 'agendador',
                'correlation_id' => Correlation::id(),
            ]);
            AuditLogger::record('directory.sync_requested', 'directory_sync_run', $run->id, ['mode' => $run->mode, 'trigger' => $trigger, 'transport' => $run->transport], null, $by);
            RunDirectorySync::dispatch($run->id)->onQueue('directory')->afterCommit();

            return $run;
        });

        return $run;
    }

    /**
     * Teste de conexão real: token app-only, permissões presentes no token e leituras mínimas.
     *
     * @param  array<string,mixed>  $cfg
     * @return array<string,mixed>
     */
    public function testConnection(array $cfg): array
    {
        $started = microtime(true);
        $checks = [];
        $stage = 'Token app-only (client credentials)';
        $all = DirectorySyncSettings::allDirectory($cfg);
        $groupIds = $all ? null : array_column($cfg['groups'], 'id');
        try {
            $canProbe = true;
            if ($cfg['transport'] === 'graph') {
                $token = $this->tokens->token($this->tokenConfig($cfg), 'https://graph.microsoft.com/.default');
                $t = EntraAppTokenProvider::summary($token);
                $granted = $t['roles'];
                $checks[] = ['name' => 'Token app-only (client credentials)', 'ok' => true, 'code' => null, 'message' => null,
                    'durationMs' => (int) round((microtime(true) - $started) * 1000),
                    'detail' => 'App: '.($t['appName'] ?? '—').' ('.($t['appId'] ?? '—').') · permissões de APLICAÇÃO no token: '.($granted ? implode(', ', $granted) : 'nenhuma')];
                foreach (DirectorySyncSettings::PERMISSIONS as $p) {
                    $ok = in_array($p['permission'], $granted, true) || ($p['permission'] === 'Group.Read.All' && in_array('GroupMember.Read.All', $granted, true));
                    if (! $ok) {
                        $canProbe = false;
                    }
                    $checks[] = ['name' => "Permissão {$p['permission']} (Application) com admin consent", 'ok' => $ok, 'code' => $ok ? null : 'consent_missing',
                        'message' => $ok ? null : "{$p['permission']} não veio no token do aplicativo. A importação roda sem usuário logado: só permissões do tipo APPLICATION valem — se no portal ela aparece como \"Delegated\", não serve. Adicione em API permissions → Microsoft Graph → Application permissions → {$p['permission']} e clique em \"Grant admin consent\"."];
                }
                $excessive = array_values(array_intersect($granted, ['Directory.ReadWrite.All', 'User.ReadWrite.All', 'Group.ReadWrite.All', 'Directory.Read.All']));
                if ($excessive) {
                    $checks[] = ['name' => 'Sem permissões de escrita no diretório', 'ok' => false, 'code' => 'excessive_permissions', 'message' => 'Remova do app: '.implode(', ', $excessive).'.'];
                }
                $others = array_values(array_diff($granted, ['User.Read.All', 'Group.Read.All', 'GroupMember.Read.All'], $excessive));
                if ($others) {
                    // não bloqueia, mas o segredo deste app fica no BPM: app compartilhado amplia o impacto de um vazamento
                    $checks[] = ['name' => 'App exclusivo do BPM (somente leitura)', 'ok' => true, 'warning' => true, 'code' => 'shared_app',
                        'message' => 'O app também tem permissões de aplicação que o BPM não usa: '.implode(', ', array_slice($others, 0, 10)).'. Recomendado: App Registration exclusivo para a importação, só com User.Read.All e Group.Read.All.'];
                }
                $fictitious = array_values(array_filter($groupIds ?? [], fn ($id) => MockGraphClient::isFictitiousGroup((string) $id)));
                if ($fictitious) {
                    $checks[] = ['name' => 'Grupos do tenant real', 'ok' => false, 'code' => 'mock_groups',
                        'message' => count($fictitious).' grupo(s) configurado(s) são do diretório fictício e não existem no Entra: troque pelos Object IDs dos grupos reais.'];
                    $groupIds = array_values(array_diff((array) $groupIds, $fictitious));
                }
            } else {
                $checks[] = ['name' => 'Diretório fictício (mock) — somente desenvolvimento', 'ok' => true, 'code' => null, 'message' => null];
            }
            if ($canProbe) {
                $stage = 'Leitura no Microsoft Graph';
                $probeStart = microtime(true);
                $graph = new EntraGraphDirectorySync($this->client($cfg));
                $probe = $graph->probe($groupIds, (bool) ($cfg['photos']['enabled'] ?? true));
                $checks = [...$checks, ...$probe];
                if (collect($probe)->every(fn ($c) => $c['ok'])) {
                    $checks[] = ['name' => 'Leituras de teste no diretório', 'ok' => true, 'code' => null, 'message' => null,
                        'durationMs' => (int) round((microtime(true) - $probeStart) * 1000), 'detail' => $groupIds === null ? 'Escopo: todo o diretório (todos os grupos e usuários do tenant)' : min(3, count($groupIds)).' de '.count($groupIds).' grupo(s) configurado(s) lido(s)'];
                }
            } else {
                $checks[] = ['name' => 'Leituras de teste no diretório', 'ok' => false, 'code' => 'consent_missing', 'message' => 'Não executadas: faltam as permissões de aplicação acima.'];
            }
        } catch (GraphException $e) {
            $checks[] = ['name' => $stage, 'ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage(), 'detail' => $e->status ? 'HTTP '.$e->status : null];
        }
        $ok = collect($checks)->every(fn ($c) => $c['ok']);
        $failed = collect($checks)->firstWhere('ok', false);

        return [
            'ok' => $ok,
            'step' => 'connection',
            'code' => $ok ? 'connection_ok' : ($failed['code'] ?? 'connection_failed'),
            'message' => $ok ? 'Conexão e permissões verificadas. Execute uma prévia antes de sincronizar.' : ($failed['message'] ?? 'Falha na conexão.'),
            'checks' => $checks,
            'durationMs' => (int) round((microtime(true) - $started) * 1000),
            'transport' => $cfg['transport'],
            'complete' => true,
        ];
    }

    public function client(array $cfg): GraphClient
    {
        if ($cfg['transport'] === 'mock') {
            if (app()->environment('production')) {
                throw new GraphException('mock_forbidden', 'Diretório fictício proibido em produção.');
            }

            return app(MockGraphClient::class);
        }
        $tokenCfg = $this->tokenConfig($cfg);

        return app()->makeWith(HttpGraphClient::class, ['tokenProvider' => fn () => $this->tokens->token($tokenCfg, 'https://graph.microsoft.com/.default')]);
    }

    /** @return array{tenantId:string, clientId:string, credentialType:string, credentialRef:?string, authorityHost:string} */
    private function tokenConfig(array $cfg): array
    {
        return ['tenantId' => (string) $cfg['tenantId'], 'clientId' => (string) $cfg['clientId'], 'credentialType' => (string) $cfg['credentialType'], 'credentialRef' => $cfg['credentialRef'] ?? null, 'authorityHost' => (string) ($cfg['authorityHost'] ?? 'login.microsoftonline.com')];
    }

    public function tenant(array $cfg): string
    {
        return $cfg['transport'] === 'mock' ? MockGraphClient::TENANT : (string) $cfg['tenantId'];
    }

    // ------------------------------------------------------------------ execução

    public function execute(string $runId): void
    {
        $run = DirectorySyncRun::find($runId);
        if (! $run || $run->status !== DirectorySyncRun::QUEUED) {
            return;
        }
        $lock = Cache::lock(self::LOCK, 3600);
        if (! $lock->get()) {
            $run->update(['status' => DirectorySyncRun::FAILED, 'error_code' => 'concurrent_run', 'error_message' => 'Outra sincronização está em andamento.', 'finished_at' => now()]);

            return;
        }
        try {
            Correlation::set($run->correlation_id);
            AuditContext::as('worker:sincronização do diretório', fn () => $this->executeLocked($run), $run->id);
        } finally {
            $lock->release();
        }
    }

    private function executeLocked(DirectorySyncRun $run): void
    {
        $started = microtime(true);
        $run->update(['status' => DirectorySyncRun::RUNNING, 'started_at' => now()]);
        $cfg = $this->config();
        $preview = $run->mode === 'preview';
        $this->s = [
            'preview' => $preview, 'tenant' => $this->tenant($cfg), 'cfg' => $cfg, 'run' => $run,
            'totals' => array_fill_keys(['created', 'updated', 'disabled', 'reenabled', 'unchanged', 'skipped', 'excluded', 'groups', 'groups_removed', 'memberships_added', 'memberships_removed', 'roles_changed', 'pages', 'fetched', 'errors'], 0),
            'errors' => [], 'sample' => [], 'blocked' => null, 'resumed' => false, 'full' => false, 'deltaReset' => false,
            'log' => [], 'started' => $started,
        ];
        $status = DirectorySyncRun::FAILED;
        $errorCode = null;
        $errorMessage = null;
        $links = null;
        $modeLabel = ['preview' => 'prévia', 'delta' => 'incremental', 'full' => 'recarga completa'][$run->mode] ?? $run->mode;
        $this->log('info', 'start', "Execução iniciada ($modeLabel) · gatilho: {$run->trigger} · transporte: ".($cfg['transport'] === 'mock' ? 'diretório fictício' : 'Microsoft Graph'),
            ['tenant' => $this->s['tenant'], 'scope' => DirectorySyncSettings::allDirectory($cfg) ? 'all' : 'groups', 'groups' => DirectorySyncSettings::allDirectory($cfg) ? 'todos' : count($cfg['groups'] ?? []), 'requestedBy' => $run->requested_by_name]);
        if ($preview) {
            $this->log('info', 'preview', 'Prévia: as alterações são calculadas e revertidas ao final — nada é gravado.');
        }

        try {
            if (! $cfg['enabled'] && ! $preview) {
                throw new GraphException('not_enabled', 'Sincronização desabilitada nas configurações.');
            }
            if ($missing = $this->type()->missing($cfg)) {
                throw new GraphException('not_configured', 'Configuração incompleta: '.implode(', ', $missing).'.');
            }
            $graph = new EntraGraphDirectorySync($this->client($cfg));
            if ($preview) {
                DB::beginTransaction();
            }
            try {
                $links = $this->round($graph, $run->mode === 'full');
            } finally {
                if ($preview) {
                    DB::rollBack();
                }
            }
            $status = $this->s['blocked'] ? DirectorySyncRun::BLOCKED : ($this->s['errors'] ? DirectorySyncRun::PARTIAL : DirectorySyncRun::COMPLETED);
            if ($this->s['blocked']) {
                [$errorCode, $errorMessage] = ['removal_threshold', $this->s['blocked']];
            }
        } catch (GraphException $e) {
            [$errorCode, $errorMessage] = [$e->errorCode, $e->getMessage()];
            $this->log('error', 'error', $e->getMessage(), array_filter(['code' => $e->errorCode, 'http' => $e->status, 'transient' => $e->transient ?: null]));
        } catch (Throwable $e) {
            Log::error('Falha na sincronização do diretório', ['run' => $run->id, 'error' => Masker::error($e)]);
            [$errorCode, $errorMessage] = ['internal_error', 'Erro interno durante a sincronização (consulte o correlation ID).'];
            $this->log('error', 'error', $errorMessage, ['code' => 'internal_error']);
        }

        $advanced = false;
        if (! $preview && $status === DirectorySyncRun::COMPLETED && $links) {
            $this->saveCheckpoint($links);
            $advanced = true;
            $this->log('success', 'checkpoint', 'Checkpoint salvo: as próximas execuções serão incrementais (delta) a partir daqui.');
        } elseif (! $preview) {
            $this->log('warning', 'checkpoint', 'Checkpoint mantido: '.match ($status) {
                DirectorySyncRun::PARTIAL => 'houve erros por registro; a próxima execução repete a partir do cursor anterior.',
                DirectorySyncRun::BLOCKED => 'a trava de segurança bloqueou remoções/desativações.',
                default => 'a execução não terminou com sucesso; nada avança até uma execução completa.',
            });
        }
        $t = $this->s['totals'];
        $this->log(match ($status) {
            DirectorySyncRun::COMPLETED => 'success', DirectorySyncRun::PARTIAL, DirectorySyncRun::BLOCKED => 'warning', default => 'error'
        }, 'finish',
            sprintf('Execução %s em %.1f s — usuários: %d criado(s), %d atualizado(s), %d desativado(s), %d inalterado(s), %d ignorado(s) por política; grupos: %d; vínculos +%d/−%d; erros: %d.',
                ['completed' => 'concluída', 'partial' => 'concluída com erros', 'blocked' => 'bloqueada pela trava', 'failed' => 'falhou'][$status],
                microtime(true) - $started, $t['created'], $t['updated'], $t['disabled'], $t['unchanged'], $t['excluded'], $t['groups'], $t['memberships_added'], $t['memberships_removed'], $t['errors']));

        $run->update([
            'status' => $status,
            'finished_at' => now(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'totals' => [...$this->s['totals'], 'full' => (int) $this->s['full'], 'resumed' => (int) $this->s['resumed'], 'delta_reset' => (int) $this->s['deltaReset']],
            'errors' => array_slice($this->s['errors'], 0, 200),
            'plan' => $preview ? ['changes' => $this->s['sample'], 'note' => 'Prévia: nenhuma alteração foi gravada.'] : null,
            'log' => $this->s['log'],
            'error_code' => $errorCode,
            'error_message' => $errorMessage ? mb_substr($errorMessage, 0, 500) : null,
            'cursor_advanced' => $advanced,
        ]);
        $this->afterRun($run->fresh(), $cfg);
    }

    private function afterRun(DirectorySyncRun $run, array $cfg): void
    {
        $action = match ($run->status) {
            DirectorySyncRun::COMPLETED, DirectorySyncRun::PARTIAL => 'directory.sync_completed',
            DirectorySyncRun::BLOCKED => 'directory.sync_blocked',
            default => 'directory.sync_failed',
        };
        AuditLogger::record($action, 'directory_sync_run', $run->id, ['mode' => $run->mode, 'status' => $run->status, 'totals' => $run->totals, 'code' => $run->error_code, 'cursorAdvanced' => $run->cursor_advanced],
            outcome: in_array($run->status, [DirectorySyncRun::COMPLETED, DirectorySyncRun::PARTIAL], true) ? 'success' : ($run->status === DirectorySyncRun::BLOCKED ? 'blocked' : 'failure'));

        $alertCodes = ['consent_missing', 'credential_invalid', 'credential_missing', 'tenant_invalid', 'app_not_found', 'removal_threshold'];
        if (in_array($run->error_code, $alertCodes, true)) {
            $admin = User::where('is_active', true)->whereNotNull('email')->get()->first(fn (User $u) => $u->isAdmin());
            if ($admin && $run->mode !== 'preview') {
                $this->settings->recordValidation($this->type(), false, ['step' => 'sync', 'code' => $run->error_code, 'message' => $run->error_message], $admin);
            }
            $this->notifier->adminAlert('directory:'.$run->id, '['.config('app.name').'] Sincronização do diretório: '.$run->error_code, "A sincronização do diretório terminou com '{$run->status}' ({$run->error_code}): {$run->error_message}\n\nVerifique em Configurações → Entra ID — usuários e grupos.");
        }
    }

    /**
     * Uma rodada completa (grupos → usuários → membros pendentes → memberships → desativações → papéis).
     * Altera o estado da execução ($this->s): totais, erros, bloqueio, retomada.
     *
     * @phpstan-impure
     *
     * @return array{groups:string, users:string, scope:string}
     */
    private function round(EntraGraphDirectorySync $graph, bool $forceFull): array
    {
        $cfg = $this->s['cfg'];
        $selected = self::scopeGroupIds($cfg);
        $scope = hash('sha256', ($selected === null ? '*' : implode(',', $selected)).'|'.EntraGraphDirectorySync::USER_SELECT.'|'.$this->s['tenant']);
        $gc = DirectorySyncCursor::firstOrCreate(['resource' => 'groups']);
        $uc = DirectorySyncCursor::firstOrCreate(['resource' => 'users']);
        $full = $forceFull || ! $gc->delta_link || ! $uc->delta_link || $gc->full_sync_required || $uc->full_sync_required || $gc->scope_hash !== $scope;
        $this->s['full'] = $full;
        $this->log('info', 'mode', $full ? 'Carga completa: '.match (true) {
            $forceFull => 'recarga completa solicitada.',
            ! $gc->delta_link || ! $uc->delta_link => 'primeira carga (ainda não há cursor delta salvo).',
            $gc->full_sync_required || $uc->full_sync_required => 'o cursor delta foi marcado para recarga (expirado/reset).',
            default => 'o escopo (grupos selecionados / todo o diretório) mudou desde o último checkpoint.',
        } : 'Incremental (delta): somente o que mudou desde o checkpoint de '.($uc->delta_saved_at?->toIso8601String() ?? '—').'.',
            ['groups' => $selected === null ? 'todos' : count($selected)]);

        try {
            return $this->roundWith($graph, $gc, $uc, $selected, $scope, $full);
        } catch (GraphException $e) {
            if ($e->errorCode !== 'delta_expired' || $full) {
                throw $e;
            }
            // Cursor expirado/reset do Graph: recarga controlada (completa) na mesma execução.
            // (não é erro de registro: a rodada completa substitui o cursor e pode avançar o checkpoint)
            $this->s['deltaReset'] = true;
            $this->log('warning', 'delta_reset', 'O Graph pediu ressincronização completa (cursor delta expirado/410): recarga completa na mesma execução.', ['code' => 'delta_expired']);
            foreach ([$gc, $uc] as $c) {
                $c->update(['delta_link' => null, 'next_link' => null, 'full_sync_required' => true]);
            }
            $this->s['full'] = true;

            return $this->roundWith($graph, $gc->fresh(), $uc->fresh(), $selected, $scope, true);
        }
    }

    /**
     * @param  list<string>|null  $selected  null = todo o diretório
     * @return array{groups:string, users:string, scope:string}
     */
    private function roundWith(EntraGraphDirectorySync $graph, DirectorySyncCursor $gc, DirectorySyncCursor $uc, ?array $selected, string $scope, bool $full): array
    {
        $all = $selected === null;
        $inScope = fn (string $oid) => $all || in_array($oid, $selected, true);
        $seenGroups = [];  // grupos recebidos nesta rodada (carga completa de todo o diretório: os ausentes foram excluídos)
        $tenant = $this->s['tenant'];
        $runId = $this->s['run']->id;
        $adds = [];        // groupOid => [userOid => true]
        $removals = [];    // [groupOid, userOid] explícitas (members@delta @removed)
        $memberSets = [];  // full: conjunto completo de membros por grupo
        $removedGroups = [];

        // Retomada: nextLink da execução interrompida (mesmo escopo) — desliga remoção por ausência.
        $groupStart = $gc->next_link && $gc->scope_hash === $scope ? $gc->next_link : ($full ? null : $gc->delta_link);
        $userStart = $uc->next_link && $uc->scope_hash === $scope ? $uc->next_link : ($full ? null : $uc->delta_link);
        if (($gc->next_link && $groupStart === $gc->next_link) || ($uc->next_link && $userStart === $uc->next_link)) {
            $this->s['resumed'] = true;
            $this->log('warning', 'resume', 'Retomando do ponto em que a execução anterior parou (nextLink salvo); a remoção por ausência fica desligada nesta rodada.');
        }

        $groupPage = 0;
        $groupsDelta = $graph->groupsDelta($selected, $groupStart, function (array $items, ?string $next) use ($tenant, $inScope, $full, &$adds, &$removals, &$memberSets, &$removedGroups, &$seenGroups, $gc, $scope, $runId, &$groupPage) {
            $this->s['totals']['pages']++;
            $groupPage++;
            $inPage = count(array_filter($items, fn ($g) => $inScope(strtolower((string) ($g['id'] ?? '')))));
            $members = array_sum(array_map(fn ($g) => $inScope(strtolower((string) ($g['id'] ?? ''))) ? count((array) ($g['members@delta'] ?? [])) : 0, $items));
            $this->logPage('groups_page', "Grupos — página $groupPage: ".count($items)." item(ns) recebido(s), $inPage no escopo, $members alteração(ões) de membros".($next ? ' (há mais páginas)' : ''), $groupPage);
            DB::transaction(function () use ($items, $tenant, $inScope, $full, &$adds, &$removals, &$memberSets, &$removedGroups, &$seenGroups) {
                foreach ($items as $g) {
                    $oid = strtolower((string) ($g['id'] ?? ''));
                    if ($oid === '' || ! $inScope($oid)) {
                        continue;
                    }
                    if (isset($g['@removed'])) {
                        $removedGroups[] = $oid;

                        continue;
                    }
                    $seenGroups[$oid] = true;
                    $this->upsertGroup($tenant, $oid, $g);
                    foreach ((array) ($g['members@delta'] ?? []) as $m) {
                        $type = (string) ($m['@odata.type'] ?? '#microsoft.graph.user');
                        $mid = strtolower((string) ($m['id'] ?? ''));
                        if ($type !== '#microsoft.graph.user' || $mid === '') {
                            continue; // grupos aninhados/dispositivos não são expandidos (somente membros diretos)
                        }
                        if (isset($m['@removed'])) {
                            $removals[] = [$oid, $mid];
                            unset($adds[$oid][$mid]);
                        } else {
                            $adds[$oid][$mid] = true;
                            if ($full) {
                                $memberSets[$oid][$mid] = true;
                            }
                        }
                    }
                }
            });
            if (! $this->s['preview']) {
                $gc->update(['next_link' => $next, 'next_link_run_id' => $next ? $runId : null, 'scope_hash' => $scope]);
            }
        });

        // Usuários: delta do tenant, aplicado a quem já existe ou é membro dos grupos do escopo (todos, em "todo o diretório").
        $known = User::where('entra_tid', $tenant)->whereNotNull('entra_oid')->pluck('id', 'entra_oid')->all();
        $candidates = [];
        foreach ($adds as $members) {
            $candidates += $members;
        }
        $this->log('info', 'groups_done', sprintf('Grupos concluídos: %d grupo(s) lido(s), %d membro(s) direto(s) encontrado(s), %d remoção(ões) de membro, %d grupo(s) removido(s) no diretório.',
            $this->s['totals']['groups'], count($candidates), count($removals), count($removedGroups)));
        $processed = [];
        $disable = [];
        $userPage = 0;
        $before = $this->s['totals'];
        $usersDelta = $graph->usersDelta($userStart, function (array $items, ?string $next) use ($tenant, $all, &$known, $candidates, &$processed, &$disable, $uc, $scope, $runId, &$userPage) {
            $this->s['totals']['pages']++;
            $userPage++;
            $inPage = $all ? count($items) : count(array_filter($items, fn ($u) => isset($known[strtolower((string) ($u['id'] ?? ''))]) || isset($candidates[strtolower((string) ($u['id'] ?? ''))])));
            $this->logPage('users_page', "Usuários — página $userPage: ".count($items)." item(ns) recebido(s), $inPage no escopo".($next ? ' (há mais páginas)' : ''), $userPage);
            DB::transaction(function () use ($items, $tenant, $all, &$known, $candidates, &$processed, &$disable) {
                foreach ($items as $u) {
                    $oid = strtolower((string) ($u['id'] ?? ''));
                    if ($oid === '' || (! $all && ! isset($known[$oid]) && ! isset($candidates[$oid]))) {
                        continue; // fora do escopo selecionado
                    }
                    $processed[$oid] = true;
                    if (isset($u['@removed'])) {
                        if (isset($known[$oid])) {
                            $disable[$oid] = 'directory_deleted';
                        }

                        continue;
                    }
                    $this->upsertUser($tenant, $oid, $u, $known, $disable);
                }
            });
            if (! $this->s['preview']) {
                $uc->update(['next_link' => $next, 'next_link_run_id' => $next ? $runId : null, 'scope_hash' => $scope]);
            }
        });

        $after = $this->s['totals'];
        $this->log('info', 'users_done', sprintf('Usuários do delta: %d criado(s), %d atualizado(s), %d inalterado(s), %d ignorado(s) por política (convidado/desabilitado), %d reativado(s).',
            $after['created'] - $before['created'], $after['updated'] - $before['updated'], $after['unchanged'] - $before['unchanged'], $after['excluded'] - $before['excluded'], $after['reenabled'] - $before['reenabled']));

        // Membros novos sem alteração recente no usuário (não vêm no users/delta): leitura pontual.
        $pending = array_diff_key($candidates, $processed, $known);
        if ($pending) {
            $this->log('info', 'fetch', 'Leitura pontual de '.count($pending).' membro(s) sem alteração recente (não vieram no delta de usuários).');
        }
        $limit = 1000;
        foreach (array_keys($pending) as $oid) {
            if ($limit-- <= 0) {
                $this->recordError('user', $oid, 'fetch_limit', 'Limite de leituras pontuais por execução atingido; continua na próxima.');

                continue;
            }
            try {
                $u = $graph->user($oid);
                $this->s['totals']['fetched']++;
                DB::transaction(fn () => $this->upsertUser($tenant, $oid, $u, $known, $disable));
            } catch (GraphException $e) {
                if (! in_array($e->errorCode, ['not_found'], true)) {
                    throw $e; // falha de rede/permissão: aborta (checkpoint não avança)
                }
                $this->recordError('user', $oid, 'not_found', 'Membro não encontrado no diretório.');
            }
        }

        $affected = [];
        DB::transaction(function () use ($tenant, $selected, $adds, $removals, $memberSets, $removedGroups, $seenGroups, $full, &$affected, &$known) {
            $groupIds = DirectoryGroup::where('tenant_id', $tenant)->when($selected !== null, fn ($q) => $q->whereIn('object_id', $selected))->pluck('id', 'object_id')->all();
            foreach ($adds as $goid => $members) {
                foreach (array_keys($members) as $uoid) {
                    if (! isset($groupIds[$goid], $known[$uoid])) {
                        continue; // usuário excluído por política (guest/desabilitado) ou não encontrado
                    }
                    $m = DirectoryMembership::firstOrNew(['directory_group_id' => $groupIds[$goid], 'user_id' => $known[$uoid]]);
                    if (! $m->exists || $m->removed_at !== null) {
                        $this->s['totals']['memberships_added']++;
                        $affected[$known[$uoid]] = true;
                    }
                    $m->fill(['source' => 'graph', 'synced_at' => now(), 'removed_at' => null])->save();
                }
            }
            $explicit = $removals;
            foreach ($removedGroups as $goid) {
                if (isset($groupIds[$goid])) {
                    DirectoryGroup::whereKey($groupIds[$goid])->update(['removed_at' => now()]);
                    $this->s['totals']['groups_removed']++;
                    foreach (DirectoryMembership::where('directory_group_id', $groupIds[$goid])->whereNull('removed_at')->get() as $m) {
                        $m->update(['removed_at' => now()]);
                        $this->s['totals']['memberships_removed']++;
                        $affected[$m->user_id] = true;
                    }
                }
            }
            foreach ($explicit as [$goid, $uoid]) {
                if (isset($groupIds[$goid], $known[$uoid])) {
                    $n = DirectoryMembership::where('directory_group_id', $groupIds[$goid])->where('user_id', $known[$uoid])->whereNull('removed_at')->update(['removed_at' => now()]);
                    if ($n) {
                        $this->s['totals']['memberships_removed'] += $n;
                        $affected[$known[$uoid]] = true;
                    }
                }
            }

            // Carga completa: remove por ausência — somente sem retomada e abaixo da trava de segurança.
            if ($full && ! $this->s['resumed']) {
                $byAbsence = [];
                $active = 0;
                foreach ($selected ?? array_keys($groupIds) as $goid) {
                    if (! isset($groupIds[$goid]) || in_array($goid, $removedGroups, true)) {
                        continue;
                    }
                    $set = $memberSets[$goid] ?? [];
                    $rows = DirectoryMembership::where('directory_group_id', $groupIds[$goid])->whereNull('removed_at')->get();
                    $active += $rows->count();
                    $oidById = array_flip($known);
                    foreach ($rows as $m) {
                        $uoid = $oidById[$m->user_id] ?? null;
                        if ($uoid !== null && ! isset($set[$uoid])) {
                            $byAbsence[] = $m;
                        }
                    }
                }
                // todo o diretório: grupo importado que não veio na carga completa foi excluído no Entra
                // (os vínculos dele já entram em $byAbsence e passam pela trava)
                $absentGroups = $selected === null
                    ? array_values(array_diff(DirectoryGroup::where('tenant_id', $tenant)->whereNull('removed_at')->pluck('object_id')->all(), array_keys($seenGroups), $removedGroups))
                    : [];
                if ($this->exceedsThreshold(count($byAbsence), $active) || $this->exceedsThreshold(count($absentGroups), count($groupIds))) {
                    $this->s['blocked'] = 'Remoção de '.count($byAbsence)." de $active vínculos".($absentGroups ? ' e '.count($absentGroups).' de '.count($groupIds).' grupos' : '').' ultrapassa a trava de segurança; nada foi removido por ausência.';
                    $this->log('warning', 'safety', $this->s['blocked'], ['code' => 'removal_threshold', 'limit' => (float) config('bpm.directory.max_removal_ratio', 0.3)]);
                } else {
                    foreach ($absentGroups as $goid) {
                        DirectoryGroup::whereKey($groupIds[$goid])->update(['removed_at' => now()]);
                        $this->s['totals']['groups_removed']++;
                    }
                    foreach ($byAbsence as $m) {
                        $m->update(['removed_at' => now()]);
                        $this->s['totals']['memberships_removed']++;
                        $affected[$m->user_id] = true;
                    }
                }
            }
        });

        $this->log('info', 'memberships', sprintf('Vínculos usuário↔grupo: +%d adicionado(s), −%d removido(s).', $this->s['totals']['memberships_added'], $this->s['totals']['memberships_removed']));

        // Desativações: só por sinal explícito do diretório, com trava contra desativação em massa.
        $directoryUsers = User::where('entra_tid', $tenant)->whereNotNull('entra_oid')->where('is_active', true)->count();
        if ($this->exceedsThreshold(count($disable), $directoryUsers)) {
            $this->s['blocked'] = 'Desativação de '.count($disable)." de $directoryUsers usuários ultrapassa a trava de segurança; nenhuma conta foi desativada.";
            $this->log('warning', 'safety', $this->s['blocked'], ['code' => 'removal_threshold', 'limit' => (float) config('bpm.directory.max_removal_ratio', 0.3)]);
        } else {
            $this->log('info', 'disable', $disable ? count($disable).' conta(s) a desativar por sinal do diretório (excluída/desabilitada no Entra).' : 'Nenhuma conta a desativar.');
            DB::transaction(function () use ($disable, &$known, &$affected) {
                foreach ($disable as $oid => $reason) {
                    $user = isset($known[$oid]) ? User::find($known[$oid]) : null;
                    if ($user && $user->is_active !== false) {
                        $user->forceFill(['is_active' => false, 'account_enabled' => $reason === 'directory_disabled' ? false : $user->account_enabled, 'disabled_reason' => $reason, 'disabled_at' => now()])->save();
                        $this->s['totals']['disabled']++;
                        $this->sample('disable', 'user', $user->name, $user->upn);
                        AuditLogger::record('directory.user_disabled', 'user', $user->id, ['reason' => $reason], context: ['object_name' => $user->name]);
                        $affected[$user->id] = true;
                    }
                }
            });
        }

        $changed = DB::transaction(fn () => $this->recomputeRolesFor(array_keys($affected), $selected));
        $this->log('info', 'roles', count($affected).' usuário(s) com vínculos alterados; papéis/raias recalculados pelos mapeamentos — '.$changed.' acesso(s) efetivo(s) mudaram.');

        return ['groups' => $groupsDelta, 'users' => $usersDelta, 'scope' => $scope];
    }

    private function exceedsThreshold(int $count, int $base): bool
    {
        $ratio = (float) config('bpm.directory.max_removal_ratio', 0.3);

        return $count > 10 && $base > 0 && ($count / $base) > $ratio;
    }

    private function upsertGroup(string $tenant, string $oid, array $g): void
    {
        $group = DirectoryGroup::firstOrNew(['tenant_id' => $tenant, 'object_id' => $oid]);
        $isNew = ! $group->exists;
        foreach (['displayName' => 'display_name', 'mail' => 'mail', 'description' => 'description', 'securityEnabled' => 'security_enabled'] as $src => $col) {
            if (array_key_exists($src, $g)) {
                $group->{$col} = is_string($g[$src]) ? mb_substr($g[$src], 0, $col === 'description' ? 1024 : 256) : $g[$src];
            }
        }
        $group->removed_at = null;
        $group->synced_at = now();
        $group->save();
        $this->s['totals']['groups']++;
        if ($isNew) {
            $this->sample('create', 'group', (string) $group->display_name, null);
        }
    }

    /**
     * @param  array<string,string>  $known  oid => user id (atualizado ao criar)
     * @param  array<string,string>  $disable
     */
    private function upsertUser(string $tenant, string $oid, array $u, array &$known, array &$disable): void
    {
        $cfg = $this->s['cfg'];
        $enabled = array_key_exists('accountEnabled', $u) ? (bool) $u['accountEnabled'] : null;
        $guest = strcasecmp((string) ($u['userType'] ?? 'Member'), 'Guest') === 0;
        $user = isset($known[$oid]) ? User::find($known[$oid]) : null;

        if (! $user) {
            if ($guest && ! $cfg['includeGuests']) {
                $this->s['totals']['excluded']++;

                return;
            }
            if ($enabled === false && ! $cfg['includeDisabled']) {
                $this->s['totals']['excluded']++;

                return;
            }
            $user = new User([
                'entra_oid' => $oid, 'entra_tid' => $tenant, 'auth_source' => User::SOURCE_ENTRA, 'is_active' => $enabled !== false,
                'token_roles' => [], 'token_groups' => [], 'directory_roles' => [], 'directory_groups' => [], 'roles' => [], 'groups' => [],
                'manual_roles' => User::defaultManualRoles(),
            ]);
        }
        $user->auth_source = User::SOURCE_ENTRA;
        $tracked = ['name', 'upn', 'email', 'department', 'registration_number', 'user_type', 'account_enabled', 'manager_entra_oid'];
        $before = $user->exists ? $user->only($tracked) : null;
        $map = ['displayName' => 'name', 'userPrincipalName' => 'upn', 'mail' => 'email', 'department' => 'department', 'userType' => 'user_type', 'accountEnabled' => 'account_enabled'];
        foreach ($map as $src => $col) {
            if (array_key_exists($src, $u)) {
                $user->{$col} = is_string($u[$src]) ? mb_substr($u[$src], 0, 200) : $u[$src];
            }
        }
        [$hasRegistration, $registration] = $this->registrationNumber($u);
        if ($hasRegistration) {
            $user->registration_number = $registration;
        }
        [$hasManager, $managerOid] = $this->managerOid($u);
        if ($hasManager) {
            $user->manager_entra_oid = $managerOid;
        }
        $user->name = $user->name ?: 'Usuário do diretório';
        if (! $user->email && $user->upn) {
            $user->email = $user->upn;
        }
        if (array_key_exists('department', $u)) {
            $user->area = $user->department;
        }
        $user->directory_synced_at = now();

        if (! $user->exists) {
            $user->recomputeEffectiveAccess();
            $user->save();
            $known[$oid] = $user->id;
            $this->resolveManagerRelationship($user, $tenant);
            $this->s['totals']['created']++;
            $this->sample('create', 'user', $user->name, $user->upn);
            AuditLogger::record('directory.user_created', 'user', $user->id, ['userType' => $user->user_type, 'enabled' => $user->is_active], context: ['object_name' => $user->name]);

            return;
        }
        if ($enabled === false) {
            $disable[$oid] = 'directory_disabled';
        } elseif ($enabled === true && $user->is_active === false && str_starts_with((string) $user->disabled_reason, 'directory_')) {
            $user->is_active = true;
            $user->disabled_reason = null;
            $user->disabled_at = null;
            $this->s['totals']['reenabled']++;
        }
        $after = $user->only($tracked);
        if ($before !== $after || $user->isDirty('is_active')) {
            $this->s['totals']['updated']++;
            $this->sample('update', 'user', $user->name, $user->upn);
        } else {
            $this->s['totals']['unchanged']++;
        }
        $user->save();
        $this->resolveManagerRelationship($user, $tenant);
    }

    /**
     * Matrícula = onPremisesExtensionAttributes.extensionAttribute1. Só altera quando o Graph trouxe o
     * atributo (delta pode omitir propriedades não alteradas); valor vazio/nulo limpa a matrícula.
     *
     * @return array{bool, ?string}
     */
    private function registrationNumber(array $user): array
    {
        if (! array_key_exists('onPremisesExtensionAttributes', $user)) {
            return [false, null];
        }
        $ext = $user['onPremisesExtensionAttributes'];
        if ($ext === null) {
            return [true, null];
        }
        if (! is_array($ext) || ! array_key_exists('extensionAttribute1', $ext)) {
            return [false, null];
        }
        $value = is_scalar($ext['extensionAttribute1']) ? trim((string) $ext['extensionAttribute1']) : '';

        return [true, $value === '' ? null : mb_substr($value, 0, 64)];
    }

    /** @return array{bool, ?string} */
    private function managerOid(array $user): array
    {
        if (array_key_exists('manager@delta', $user)) {
            $manager = array_values((array) $user['manager@delta'])[0] ?? null;

            return [true, is_array($manager) && isset($manager['id']) ? strtolower((string) $manager['id']) : null];
        }
        if (array_key_exists('manager', $user)) {
            $manager = $user['manager'];

            return [true, is_array($manager) && isset($manager['id']) ? strtolower((string) $manager['id']) : null];
        }

        return [false, null];
    }

    /** Resolve only inside the same Entra tenant; keep the OID when the manager is imported later. */
    private function resolveManagerRelationship(User $user, string $tenant): void
    {
        $managerId = null;
        if ($user->manager_entra_oid && $user->manager_entra_oid !== $user->entra_oid) {
            $managerId = User::where('entra_tid', $tenant)->where('entra_oid', $user->manager_entra_oid)->value('id');
        }
        if ($user->manager_user_id !== $managerId) {
            $user->manager_user_id = $managerId;
            $user->save();
        }

        if ($user->entra_oid) {
            User::where('entra_tid', $tenant)
                ->where('manager_entra_oid', $user->entra_oid)
                ->where('id', '<>', $user->id)
                ->update(['manager_user_id' => $user->id]);
        }
    }

    /**
     * Recalcula papéis/grupos vindos do diretório pelos mapeamentos explícitos (negar por padrão).
     *
     * @param  list<string>|null  $userIds  null = todos os usuários do diretório
     */
    public function recomputeRoles(?array $userIds = null): int
    {
        return $this->recomputeRolesFor($userIds, self::scopeGroupIds($this->config()));
    }

    /**
     * Grupos do escopo: object IDs selecionados (ordenados) ou null = todo o diretório.
     *
     * @return list<string>|null
     */
    public static function scopeGroupIds(array $cfg): ?array
    {
        if (DirectorySyncSettings::allDirectory($cfg)) {
            return null;
        }
        $ids = array_map(fn ($id) => strtolower((string) $id), array_column($cfg['groups'] ?? [], 'id'));
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<string>|null  $userIds
     * @param  list<string>|null  $selected  null = todos os grupos do diretório
     */
    private function recomputeRolesFor(?array $userIds, ?array $selected): int
    {
        $mappings = DirectoryRoleMapping::where('enabled', true)->get()->groupBy(fn ($m) => strtolower($m->entra_group_id));
        $validRoles = array_values((array) config('bpm.roles'));
        $privileged = (array) config('bpm.privileged_roles');
        $validGroups = BpmGroup::pluck('key')->all();
        $query = User::query()->whereNotNull('entra_oid');
        if ($userIds !== null) {
            if ($userIds === []) {
                return 0;
            }
            $query->whereIn('id', $userIds);
        }
        $changed = 0;
        foreach ($query->get() as $user) {
            $groupOids = DirectoryMembership::query()
                ->join('directory_group', 'directory_group.id', '=', 'directory_membership.directory_group_id')
                ->where('directory_membership.user_id', $user->id)
                ->whereNull('directory_membership.removed_at')
                ->whereNull('directory_group.removed_at')
                ->when($selected !== null, fn ($q) => $q->whereIn('directory_group.object_id', $selected ?: ['-']))
                ->pluck('directory_group.object_id')->map(fn ($v) => strtolower((string) $v))->all();
            $roles = [];
            $groups = [];
            foreach ($groupOids as $goid) {
                foreach ($mappings[$goid] ?? [] as $mapping) {
                    if ($mapping->tmsa_group_key && in_array($mapping->tmsa_group_key, $validGroups, true)) {
                        $groups[] = $mapping->tmsa_group_key;
                    }
                    foreach ((array) $mapping->tmsa_roles as $role) {
                        if (in_array($role, $validRoles, true) && (! in_array($role, $privileged, true) || $mapping->privileged)) {
                            $roles[] = $role;
                        }
                    }
                }
            }
            $roles = array_values(array_unique($roles));
            $groups = array_values(array_unique($groups));
            sort($roles);
            sort($groups);
            $before = ['roles' => $user->directory_roles ?? [], 'groups' => $user->directory_groups ?? []];
            if ($before === ['roles' => $roles, 'groups' => $groups] && $user->directory_roles !== null) {
                continue;
            }
            $effectiveBefore = ['roles' => $user->roles ?? [], 'groups' => $user->groups ?? []];
            $user->directory_roles = $roles;
            $user->directory_groups = $groups;
            $user->token_roles ??= [];
            $user->token_groups ??= [];
            $user->recomputeEffectiveAccess();
            $user->save();
            $effectiveAfter = ['roles' => $user->roles, 'groups' => $user->groups];
            if ($effectiveBefore !== $effectiveAfter) {
                $changed++;
                if (isset($this->s['totals'])) {
                    $this->s['totals']['roles_changed']++;
                    $this->sample('roles', 'user', $user->name, $user->upn, $effectiveAfter);
                }
                AuditLogger::record('directory.user_roles_changed', 'user', $user->id, ['source' => 'directory'], diff: AuditDiff::between($effectiveBefore, $effectiveAfter), context: ['object_name' => $user->name]);
            }
        }

        return $changed;
    }

    /** @param array{groups:string, users:string, scope:string} $links */
    private function saveCheckpoint(array $links): void
    {
        DB::transaction(function () use ($links) {
            foreach (['groups' => $links['groups'], 'users' => $links['users']] as $resource => $link) {
                DirectorySyncCursor::updateOrCreate(['resource' => $resource], [
                    'delta_link' => $link ?: null, 'next_link' => null, 'next_link_run_id' => null,
                    'scope_hash' => $links['scope'], 'delta_saved_at' => now(), 'full_sync_required' => false,
                ]);
            }
        });
    }

    private function recordError(string $type, ?string $id, string $code, string $message): void
    {
        $this->s['errors'][] = ['type' => $type, 'id' => $id, 'code' => $code, 'message' => $message];
        $this->s['totals']['errors']++;
        if ($this->s['totals']['errors'] <= 20) {
            $this->log('warning', 'record_error', "Erro no registro ($type): $message", ['code' => $code, 'id' => $id]);
        }
    }

    /**
     * Log por etapa da execução (exibido em Configurações → Entra ID). Sem tokens, cursores ou PII:
     * somente contagens, códigos e mensagens já sanitizadas.
     *
     * @param  array<string,mixed>  $data
     */
    private function log(string $level, string $step, string $message, array $data = []): void
    {
        if (! isset($this->s['log']) || count($this->s['log']) >= 400) {
            return;
        }
        $this->s['log'][] = array_filter([
            'at' => now()->toIso8601String(),
            'ms' => (int) round((microtime(true) - (float) ($this->s['started'] ?? microtime(true))) * 1000),
            'level' => $level, 'step' => $step, 'message' => mb_substr($message, 0, 500),
            'data' => $data ? Masker::mask($data) : null,
        ], fn ($v) => $v !== null);
    }

    /** Páginas: registra as 30 primeiras de cada recurso e, depois, uma a cada 25 (evita logs gigantes). */
    private function logPage(string $step, string $message, int $page): void
    {
        if ($page <= 30 || $page % 25 === 0) {
            $this->log('info', $step, $message);
        }
    }

    /** @param array<string,mixed>|null $extra */
    private function sample(string $action, string $type, string $label, ?string $upn, ?array $extra = null): void
    {
        if (! ($this->s['preview'] ?? false) || count($this->s['sample']) >= 100) {
            return;
        }
        $this->s['sample'][] = array_filter(['action' => $action, 'type' => $type, 'label' => $label, 'hint' => Masker::emailHint($upn), 'access' => $extra], fn ($v) => $v !== null);
    }

    /** Estado resumido para a tela (sem cursores). */
    public function overview(): array
    {
        $cfg = $this->config();
        $last = DirectorySyncRun::where('mode', '!=', 'preview')->latest('created_at')->first();
        $lastOk = DirectorySyncRun::where('mode', '!=', 'preview')->whereIn('status', [DirectorySyncRun::COMPLETED, DirectorySyncRun::PARTIAL])->latest('finished_at')->first();
        $cursor = DirectorySyncCursor::where('resource', 'users')->first();
        $alerts = [];
        if ($cfg['enabled'] && (! $lastOk || $lastOk->finished_at?->lt(now()->subHours((int) $cfg['staleAfterHours'])))) {
            $alerts[] = ['code' => 'stale', 'message' => 'Nenhuma sincronização concluída nas últimas '.$cfg['staleAfterHours'].' horas.'];
        }
        if ($last && in_array($last->error_code, ['consent_missing', 'credential_invalid', 'credential_missing', 'tenant_invalid', 'app_not_found'], true)) {
            $alerts[] = ['code' => $last->error_code, 'message' => (string) $last->error_message];
        }
        if ($cursor?->full_sync_required) {
            $alerts[] = ['code' => 'delta_expired', 'message' => 'Cursor delta expirado: a próxima execução fará recarga completa.'];
        }
        $certificate = $cfg['certificate']['notAfter'] ?? null;
        if ($certificate && strtotime((string) $certificate) < strtotime('+30 days')) {
            $alerts[] = ['code' => 'certificate_expiring', 'message' => 'Certificado do app de sincronização expira em '.substr((string) $certificate, 0, 10).'.'];
        }
        $next = null;
        if ($cfg['enabled'] && $cfg['scheduleMinutes'] > 0) {
            $base = $last->finished_at ?? now();
            $next = $base->copy()->addMinutes((int) $cfg['scheduleMinutes'])->toIso8601String();
        }

        return [
            'cursor' => ['present' => (bool) $cursor?->delta_link, 'savedAt' => $cursor?->delta_saved_at?->toIso8601String(), 'fullSyncRequired' => (bool) $cursor?->full_sync_required, 'resumable' => (bool) $cursor?->next_link],
            'lastSuccessAt' => $lastOk?->finished_at?->toIso8601String(),
            'nextRunAt' => $next,
            'alerts' => $alerts,
            'counts' => [
                'users' => User::whereNotNull('directory_synced_at')->count(),
                'activeUsers' => User::whereNotNull('directory_synced_at')->where('is_active', true)->count(),
                'groups' => DirectoryGroup::whereNull('removed_at')->count(),
                'memberships' => DirectoryMembership::whereNull('removed_at')->count(),
            ],
        ];
    }

    /** Chamado pelo agendador: dispara se a periodicidade venceu. */
    public function runIfDue(): ?DirectorySyncRun
    {
        $cfg = $this->config();
        if (! $cfg['enabled'] || (int) $cfg['scheduleMinutes'] <= 0 || $this->type()->missing($cfg)) {
            return null;
        }
        $last = DirectorySyncRun::where('mode', '!=', 'preview')->latest('created_at')->first();
        if ($last && ($last->isActive() || $last->created_at?->gt(now()->subMinutes((int) $cfg['scheduleMinutes'])))) {
            return null;
        }

        return $this->request('delta', 'schedule', null);
    }
}
