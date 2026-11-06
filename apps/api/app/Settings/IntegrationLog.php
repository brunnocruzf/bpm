<?php

namespace App\Settings;

use App\Models\AuditEvent;
use App\Models\DirectorySyncRun;
use App\Models\NotificationMessage;
use App\Models\ProcessInstance;
use App\Models\SettingValidation;
use App\Settings\Types\EmailSettings;
use App\Support\IntegrationErrors;
use App\Support\Masker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Log das integrações em Configurações (e-mail e Entra ID como um todo), montado a partir de fontes já
 * redigidas: histórico de validações/testes, auditoria (configuração, acessos, mapeamentos, usuários),
 * execuções da importação (com log por etapa) e entregas de e-mail (com log por tentativa).
 * Cada entrada traz nível, resumo, etapas, fatos e explicação do código de erro (IntegrationErrors).
 * Paginação exata entre fontes: o cursor guarda quantas linhas de cada fonte já foram consumidas.
 */
final class IntegrationLog
{
    public const AREAS = ['email', 'entra', 'storage', 'backup'];

    /** kinds por área */
    public const KINDS = [
        'email' => ['test', 'delivery', 'config'],
        'entra' => ['test', 'sync', 'access', 'config', 'mapping', 'user'],
        'storage' => ['test', 'config'],
        'backup' => ['test', 'config'],
    ];

    private const SETTINGS = ['email' => ['email'], 'entra' => ['entra.auth', 'entra.directory'], 'storage' => ['attachments.storage'], 'backup' => ['backups']];

    private const CONFIG_ACTIONS = ['settings.updated', 'settings.secret_updated', 'settings.activated', 'settings.disabled'];

    private const ACCESS_ACTIONS = ['auth.login', 'auth.logout', 'auth.token_rejected', 'auth.login_blocked', 'auth.roles_changed'];

    private const MAPPING_ACTIONS = ['directory.mapping_created', 'directory.mapping_updated', 'directory.mapping_deleted'];

    private const USER_ACTIONS = ['directory.user_created', 'directory.user_disabled', 'directory.user_roles_changed'];

    private const EVENT_LABELS = [
        'email.test' => 'E-mail de teste', 'task.assigned' => 'Notificação de etapa (nova tarefa)', 'stage.completed' => 'Aviso de etapa concluída',
        'instance.ended' => 'Solicitação encerrada', 'admin.alert' => 'Alerta para administradores',
    ];

    private const STATE_LABELS = ['queued' => 'Na fila', 'sending' => 'Enviando', 'sent' => 'Aceito pelo provedor', 'retrying' => 'Aguardando nova tentativa', 'failed' => 'Falhou (dead-letter)', 'skipped' => 'Descartado'];

    /**
     * @param  array{kinds?: list<string>, subsystem?: ?string, failuresOnly?: bool, q?: ?string, since?: ?string}  $filters
     * @return array{data: list<array<string,mixed>>, next: ?string, stats: array<string,mixed>}
     */
    public function page(string $area, array $filters, int $limit = 30, ?string $cursor = null): array
    {
        if (! in_array($area, self::AREAS, true)) {
            throw new InvalidArgumentException('Área de log inválida.');
        }
        $limit = max(5, min(100, $limit));
        $kinds = array_values(array_intersect($filters['kinds'] ?? self::KINDS[$area], self::KINDS[$area])) ?: self::KINDS[$area];
        $since = self::since($filters['since'] ?? null);
        $offsets = $cursor ? (array) json_decode((string) base64_decode($cursor, true), true) : [];

        $rows = [];
        $fetched = [];
        foreach ($this->sources($area, $kinds, $filters, $since) as $name => [$query, $timeCol, $map]) {
            $off = (int) ($offsets[$name] ?? 0);
            $items = $query->orderByDesc($timeCol)->orderByDesc('id')->offset($off)->limit($limit + 1)->get();
            $fetched[$name] = $items->count();
            foreach ($items->take($limit) as $item) {
                $rows[] = ['src' => $name, 'ts' => $item->{$timeCol}?->getTimestamp() ?? 0, 'item' => $item, 'map' => $map];
            }
        }
        usort($rows, fn ($a, $b) => $b['ts'] <=> $a['ts']);
        $page = array_slice($rows, 0, $limit);
        $taken = [];
        foreach ($page as $r) {
            $taken[$r['src']] = ($taken[$r['src']] ?? 0) + 1;
        }
        $hasMore = count($rows) > $limit || collect($fetched)->contains(fn ($n) => $n > $limit);
        $next = null;
        if ($hasMore) {
            $newOffsets = [];
            foreach (array_keys($fetched) as $name) {
                $newOffsets[$name] = (int) ($offsets[$name] ?? 0) + ($taken[$name] ?? 0);
            }
            $next = base64_encode((string) json_encode($newOffsets));
        }
        $data = $this->present($page);

        return ['data' => $data, 'next' => $next, 'stats' => $cursor ? [] : $this->stats($area, $since)];
    }

    /**
     * @param  list<string>  $kinds
     * @param  array<string,mixed>  $filters
     * @return array<string, array{0: Builder<Model>, 1: string, 2: string}>
     */
    private function sources(string $area, array $kinds, array $filters, ?Carbon $since): array
    {
        $failures = (bool) ($filters['failuresOnly'] ?? false);
        $q = trim((string) ($filters['q'] ?? ''));
        $like = $q !== '' ? '%'.mb_strtolower(str_replace(['%', '_'], ['\%', '\_'], mb_substr($q, 0, 80))).'%' : null;
        $keys = self::SETTINGS[$area];
        if ($area === 'entra' && in_array($filters['subsystem'] ?? null, ['login', 'directory'], true)) {
            $keys = [$filters['subsystem'] === 'login' ? 'entra.auth' : 'entra.directory'];
        }
        $sub = $area === 'entra' ? ($filters['subsystem'] ?? null) : null;
        $out = [];

        if (in_array('test', $kinds, true)) {
            $v = SettingValidation::query()->whereIn('setting_key', $keys);
            $since && $v->where('created_at', '>=', $since);
            $failures && $v->where('ok', false);
            $like && $v->where(fn ($w) => $w->whereRaw('lower(coalesce(code, \'\')) like ?', [$like])->orWhereRaw('lower(coalesce(message, \'\')) like ?', [$like]));
            $out['val'] = [$v, 'created_at', 'validation'];
        }

        $actions = [];
        if (in_array('config', $kinds, true)) {
            $actions = [...$actions, ...self::CONFIG_ACTIONS];
        }
        if ($area === 'entra') {
            if (in_array('access', $kinds, true) && $sub !== 'directory') {
                $actions = [...$actions, ...self::ACCESS_ACTIONS];
            }
            if (in_array('mapping', $kinds, true) && $sub !== 'login') {
                $actions = [...$actions, ...self::MAPPING_ACTIONS];
            }
            if (in_array('user', $kinds, true) && $sub !== 'login') {
                $actions = [...$actions, ...self::USER_ACTIONS];
            }
        }
        if ($actions) {
            $a = AuditEvent::query()->whereIn('action', $actions)->where(function ($w) use ($keys) {
                // configuração: só das seções desta área; acessos Entra: só login Microsoft (não visitante/SuperAdmin)
                $w->where(fn ($c) => $c->whereIn('action', self::CONFIG_ACTIONS)->whereIn('data->setting', $keys))
                    ->orWhereIn('action', [...self::MAPPING_ACTIONS, ...self::USER_ACTIONS, 'auth.token_rejected'])
                    ->orWhere(fn ($c) => $c->whereIn('action', ['auth.login', 'auth.logout'])->where('data->method', 'entra'))
                    ->orWhere(fn ($c) => $c->whereIn('action', ['auth.login_blocked', 'auth.roles_changed'])->whereNotNull('actor_oid'));
            });
            $since && $a->where('occurred_at', '>=', $since);
            $failures && $a->whereIn('outcome', ['failure', 'blocked']);
            $like && $a->where(fn ($w) => $w->whereRaw('lower(action) like ?', [$like])->orWhereRaw('lower(coalesce(object_name, \'\')) like ?', [$like])
                ->orWhereRaw('lower(coalesce(actor_name, \'\')) like ?', [$like])->orWhereRaw('lower(cast(data as text)) like ?', [$like]));
            $out['audit'] = [$a, 'occurred_at', 'audit'];
        }

        if ($area === 'entra' && in_array('sync', $kinds, true) && $sub !== 'login') {
            $r = DirectorySyncRun::query();
            $since && $r->where('created_at', '>=', $since);
            $failures && $r->whereIn('status', [DirectorySyncRun::FAILED, DirectorySyncRun::BLOCKED, DirectorySyncRun::PARTIAL]);
            $like && $r->where(fn ($w) => $w->whereRaw('lower(coalesce(error_code, \'\')) like ?', [$like])->orWhereRaw('lower(coalesce(error_message, \'\')) like ?', [$like])
                ->orWhereRaw('lower(coalesce(requested_by_name, \'\')) like ?', [$like]));
            $out['run'] = [$r, 'created_at', 'run'];
        }

        if ($area === 'email' && in_array('delivery', $kinds, true)) {
            $m = NotificationMessage::query();
            $since && $m->where('created_at', '>=', $since);
            $failures && $m->whereIn('state', [NotificationMessage::FAILED, NotificationMessage::RETRYING, NotificationMessage::SKIPPED]);
            $like && $m->where(fn ($w) => $w->whereRaw('lower(subject) like ?', [$like])->orWhereRaw('lower(coalesce(recipient_address, \'\')) like ?', [$like])
                ->orWhereRaw('lower(coalesce(error_code, \'\')) like ?', [$like])->orWhereRaw('lower(event) like ?', [$like])->orWhereRaw('lower(coalesce(recipient_name, \'\')) like ?', [$like]));
            $out['msg'] = [$m, 'created_at', 'message'];
        }

        /** @var array<string, array{0: Builder<Model>, 1: string, 2: string}> $out */
        return $out;
    }

    /**
     * @param  list<array{src:string, ts:int, item:Model, map:string}>  $page
     * @return list<array<string,mixed>>
     */
    private function present(array $page): array
    {
        $instanceIds = collect($page)->map(fn ($r) => $r['item'] instanceof NotificationMessage ? $r['item']->process_instance_id : null)->filter()->unique()->values()->all();
        $numbers = $instanceIds ? ProcessInstance::whereIn('id', $instanceIds)->pluck('number', 'id')->all() : [];

        $out = [];
        foreach ($page as $r) {
            $i = $r['item'];
            $out[] = match (true) {
                $i instanceof SettingValidation => $this->fromValidation($i),
                $i instanceof AuditEvent => $this->fromAudit($i),
                $i instanceof DirectorySyncRun => $this->fromRun($i),
                $i instanceof NotificationMessage => $this->fromMessage($i, $numbers),
                default => throw new InvalidArgumentException('Fonte de log desconhecida.'),
            };
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function fromValidation(SettingValidation $v): array
    {
        $title = match ($v->setting_key.'/'.$v->step) {
            'email/connection' => 'Teste de conexão do e-mail',
            'email/test_email' => 'Resultado do e-mail de teste',
            'entra.auth/discovery' => 'Descoberta OIDC do tenant',
            'entra.auth/token' => 'Teste de login e access token',
            'entra.auth/config' => 'Validação do login',
            'entra.directory/connection' => 'Teste de conexão e permissões (Graph)',
            'entra.directory/sync' => 'Alerta da sincronização',
            'attachments.storage/connection' => 'Teste do armazenamento de anexos',
            default => 'Validação da configuração',
        };
        $d = (array) ($v->details ?? []);
        $facts = [];
        if ($v->provider) {
            $facts[] = ['label' => $v->setting_key === 'email' ? 'Provedor' : 'Transporte', 'value' => EmailSettings::PROVIDERS[$v->provider] ?? ($v->provider === 'mock' ? 'Diretório fictício' : ($v->provider === 'graph' ? 'Microsoft Graph' : ($v->provider === 'azure_blob' ? 'Azure Blob Storage' : $v->provider)))];
        }
        if ($v->setting_version) {
            $facts[] = ['label' => 'Versão da configuração', 'value' => 'v'.$v->setting_version];
        }
        if (! empty($d['tenantGuid'])) {
            $facts[] = ['label' => 'Tenant (GUID)', 'value' => (string) $d['tenantGuid']];
        }
        if (! empty($d['jwksUri'])) {
            $facts[] = ['label' => 'JWKS', 'value' => (string) $d['jwksUri']];
        }
        if (! empty($d['claimsSummary']) && is_array($d['claimsSummary'])) {
            $c = $d['claimsSummary'];
            $facts[] = ['label' => 'Token validado', 'value' => sprintf('audience %s · scopes %s · versão %s · %s app role(s)', $c['audience'] ?? '—', $c['scopes'] ?? '—', $c['version'] ?? '—', $c['appRoles'] ?? 0)];
        }
        if (! empty($d['testMessageId'])) {
            $facts[] = ['label' => 'Mensagem de teste', 'value' => (string) $d['testMessageId']];
        }

        return [
            'id' => 'val:'.$v->id, 'at' => $v->created_at->toIso8601String(), 'kind' => 'test', 'subsystem' => self::subsystem($v->setting_key),
            'level' => $v->ok ? 'success' : 'error', 'title' => $title, 'summary' => $v->message, 'actor' => $v->actor_name,
            'code' => $v->code, 'explain' => $v->ok ? null : IntegrationErrors::explain($v->code, self::subsystem($v->setting_key)), 'durationMs' => $v->duration_ms, 'correlationId' => $v->correlation_id,
            'steps' => array_map(fn ($c) => [
                'name' => (string) ($c['name'] ?? 'Etapa'), 'ok' => (bool) ($c['ok'] ?? false),
                'level' => ($c['ok'] ?? false) ? (! empty($c['warning']) ? 'warning' : 'success') : 'error',
                'code' => $c['code'] ?? null, 'message' => $c['message'] ?? null, 'detail' => $c['detail'] ?? null, 'durationMs' => $c['durationMs'] ?? null,
                'explain' => ($c['ok'] ?? false) && empty($c['warning']) ? null : IntegrationErrors::explain($c['code'] ?? null, self::subsystem($v->setting_key)),
            ], $v->checks ?? []),
            'facts' => $facts,
        ];
    }

    /** @return array<string,mixed> */
    private function fromAudit(AuditEvent $e): array
    {
        $data = (array) $e->data;
        $setting = (string) ($data['setting'] ?? '');
        $settingLabel = ['email' => 'E-mail e notificações', 'entra.auth' => 'Entra ID — login', 'entra.directory' => 'Entra ID — importação', 'attachments.storage' => 'Armazenamento de anexos'][$setting] ?? $setting;
        [$kind, $title, $level, $summary] = match ($e->action) {
            'settings.updated' => ['config', 'Configuração salva — '.$settingLabel, 'info', 'Versão '.($data['version'] ?? '?').' salva; estado: '.($data['status'] ?? '—').($e->diff ? ' · '.count($e->diff).' campo(s) alterado(s)' : '').'.'],
            'settings.secret_updated' => ['config', 'Segredo substituído — '.$settingLabel, 'info', 'Credencial gravada no cofre (o valor nunca é exibido nem registrado).'],
            'settings.activated' => ['config', 'Configuração ativada — '.$settingLabel, 'success', 'Versão '.($data['version'] ?? '?').' passou a valer.'],
            'settings.disabled' => ['config', 'Configuração desativada — '.$settingLabel, 'warning', 'Voltou a valer a configuração do ambiente / integração desligada.'],
            'auth.login' => ['access', 'Login Microsoft (Entra ID)', 'success', ($e->object_name ?? 'Usuário').' entrou com a conta corporativa.'],
            'auth.logout' => ['access', 'Saída', 'info', ($e->object_name ?? 'Usuário').' saiu da aplicação.'],
            'auth.token_rejected' => ['access', 'Login Microsoft recusado pela API', 'error', IntegrationErrors::explain((string) ($data['reason'] ?? ''))['title'] ?? 'Access token recusado.'],
            'auth.login_blocked' => ['access', 'Acesso bloqueado', 'warning', ($e->object_name ?? 'Usuário').' tentou entrar, mas a conta está desativada ('.($data['reason'] ?? 'inativa').').'],
            'auth.roles_changed' => ['access', 'Papéis do token alterados', 'info', 'App Roles/grupos do login de '.($e->object_name ?? 'usuário').' mudaram.'],
            'directory.mapping_created' => ['mapping', 'Mapeamento de grupo criado', 'info', 'Grupo '.($data['entraGroup'] ?? '—').' → '.implode(', ', array_filter([(string) ($data['tmsaGroup'] ?? ''), ...((array) ($data['roles'] ?? []))])).'.'],
            'directory.mapping_updated' => ['mapping', 'Mapeamento de grupo alterado', 'info', 'Mapeamento '.($e->object_name ?? '').' alterado.'],
            'directory.mapping_deleted' => ['mapping', 'Mapeamento de grupo removido', 'warning', 'Grupo '.($data['entraGroup'] ?? '—').' deixou de conceder acesso.'],
            'directory.user_created' => ['user', 'Usuário importado do diretório', 'info', ($e->object_name ?? 'Usuário').' importado ('.($data['userType'] ?? 'Member').').'],
            'directory.user_disabled' => ['user', 'Usuário desativado pelo diretório', 'warning', ($e->object_name ?? 'Usuário').' desativado: '.($data['reason'] ?? '—').'.'],
            'directory.user_roles_changed' => ['user', 'Acesso alterado pelo diretório', 'info', 'Papéis/raias de '.($e->object_name ?? 'usuário').' recalculados pelos mapeamentos.'],
            default => ['config', $e->action, 'info', null],
        };
        $code = $e->action === 'auth.token_rejected' ? (string) ($data['reason'] ?? 'token_invalid') : ($data['code'] ?? null);
        $facts = [];
        foreach ((array) ($data['unverifiedClaims'] ?? []) as $k => $val) {
            $facts[] = ['label' => strtoupper((string) $k).' (não verificado)', 'value' => (string) $val];
        }
        if (! empty($data['justification'])) {
            $facts[] = ['label' => 'Justificativa', 'value' => (string) $data['justification']];
        }

        return [
            'id' => 'audit:'.$e->id, 'at' => $e->occurred_at->toIso8601String(), 'kind' => $kind,
            'subsystem' => $kind === 'config' ? self::subsystem($setting) : ($kind === 'access' ? 'login' : 'directory'),
            'level' => $e->outcome === 'failure' ? 'error' : ($e->outcome === 'blocked' ? 'warning' : $level), 'title' => $title, 'summary' => $summary,
            'actor' => $e->actor_name, 'code' => $code, 'explain' => in_array($e->outcome, ['failure', 'blocked'], true) || $e->action === 'auth.token_rejected' ? IntegrationErrors::explain(is_string($code) ? $code : null) : null,
            'durationMs' => null, 'correlationId' => $e->correlation_id, 'steps' => [], 'facts' => $facts,
            'diff' => collect((array) ($e->diff ?? []))->map(fn ($c, $field) => ['field' => (string) $field, 'from' => self::scalar($c['from'] ?? null), 'to' => self::scalar($c['to'] ?? null)])->values()->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function fromRun(DirectorySyncRun $r): array
    {
        $t = (array) $r->totals;
        $mode = ['preview' => 'Prévia (dry run)', 'delta' => 'Incremental', 'full' => 'Recarga completa'][$r->mode] ?? $r->mode;
        $statusLabel = ['queued' => 'na fila', 'running' => 'em andamento', 'completed' => 'concluída', 'partial' => 'concluída com erros', 'failed' => 'falhou', 'blocked' => 'bloqueada pela trava'][$r->status] ?? $r->status;
        $summary = $r->error_message ?: (in_array($r->status, [DirectorySyncRun::QUEUED, DirectorySyncRun::RUNNING], true) ? 'Execução '.$statusLabel.' no worker.' : sprintf(
            'Usuários: %d criado(s), %d atualizado(s), %d desativado(s), %d inalterado(s) · grupos: %d · vínculos +%d/−%d · erros: %d.',
            $t['created'] ?? 0, $t['updated'] ?? 0, $t['disabled'] ?? 0, $t['unchanged'] ?? 0, $t['groups'] ?? 0, $t['memberships_added'] ?? 0, $t['memberships_removed'] ?? 0, $t['errors'] ?? 0));
        $facts = [
            ['label' => 'Situação', 'value' => ucfirst($statusLabel)],
            ['label' => 'Transporte', 'value' => $r->transport === 'mock' ? 'Diretório fictício' : 'Microsoft Graph'],
            ['label' => 'Gatilho', 'value' => ['manual' => 'manual', 'schedule' => 'agendado', 'preview' => 'prévia'][$r->trigger] ?? $r->trigger],
            ['label' => 'Checkpoint (cursor delta)', 'value' => $r->mode === 'preview' ? 'não se aplica (prévia)' : ($r->cursor_advanced ? 'avançou' : 'mantido')],
            ['label' => 'Páginas lidas · leituras pontuais', 'value' => ($t['pages'] ?? 0).' · '.($t['fetched'] ?? 0)],
            ['label' => 'Ignorados por política · reativados · papéis alterados', 'value' => ($t['excluded'] ?? 0).' · '.($t['reenabled'] ?? 0).' · '.($t['roles_changed'] ?? 0)],
        ];
        if (! empty($t['full'])) {
            $facts[] = ['label' => 'Carga', 'value' => 'completa'.(! empty($t['delta_reset']) ? ' (cursor expirado → recarga automática)' : '').(! empty($t['resumed']) ? ' · retomada' : '')];
        }
        $level = match ($r->status) {
            DirectorySyncRun::COMPLETED => 'success',
            DirectorySyncRun::PARTIAL, DirectorySyncRun::BLOCKED => 'warning',
            DirectorySyncRun::FAILED => 'error',
            default => 'info',
        };

        return [
            'id' => 'run:'.$r->id, 'runId' => $r->id, 'at' => ($r->created_at ?? now())->toIso8601String(), 'kind' => 'sync', 'subsystem' => 'directory',
            'level' => $level, 'title' => 'Importação de usuários e grupos — '.$mode, 'summary' => $summary,
            'actor' => $r->requested_by_name ?? ($r->trigger === 'schedule' ? 'agendador' : 'sistema'),
            'code' => $r->error_code, 'explain' => IntegrationErrors::explain($r->error_code, 'directory'), 'durationMs' => $r->duration_ms, 'correlationId' => $r->correlation_id,
            // log por etapa da execução (execuções antigas, anteriores ao log, não têm etapas)
            'steps' => array_map(fn ($s) => [
                'name' => $s['message'], 'ok' => ! in_array($s['level'], ['error', 'warning'], true), 'level' => $s['level'],
                'code' => $s['data']['code'] ?? null, 'message' => null, 'detail' => '+'.number_format($s['ms'] / 1000, 1, ',', '.').' s', 'at' => $s['at'],
                'durationMs' => null, 'explain' => in_array($s['level'], ['error', 'warning'], true) ? IntegrationErrors::explain($s['data']['code'] ?? null, 'directory') : null,
            ], $r->log ?? []),
            'facts' => $facts,
            'records' => array_slice(array_values((array) ($r->errors ?? [])), 0, 50),
        ];
    }

    /**
     * @param  array<string,string>  $numbers
     * @return array<string,mixed>
     */
    private function fromMessage(NotificationMessage $m, array $numbers): array
    {
        $level = match ($m->state) {
            NotificationMessage::SENT => 'success',
            NotificationMessage::FAILED => 'error',
            NotificationMessage::RETRYING, NotificationMessage::SKIPPED => 'warning',
            default => 'info',
        };
        $to = $m->recipient === 'none' ? 'sem destinatário' : (Masker::emailHint($m->recipient_address) ?? $m->recipient);
        $facts = array_values(array_filter([
            ['label' => 'Destinatário', 'value' => trim(($m->recipient_name ? $m->recipient_name.' · ' : '').$to).' ('.$m->recipient.')'],
            ['label' => 'Assunto', 'value' => $m->subject],
            ['label' => 'Situação', 'value' => self::STATE_LABELS[$m->state] ?? $m->state],
            ['label' => 'Tentativas', 'value' => $m->attempts.' de '.$m->max_attempts],
            $m->provider ? ['label' => 'Provedor', 'value' => EmailSettings::PROVIDERS[$m->provider] ?? $m->provider] : null,
            $m->provider_message_id ? ['label' => 'ID no provedor (Message-ID)', 'value' => $m->provider_message_id] : null,
            $m->sent_at ? ['label' => 'Aceito em', 'value' => $m->sent_at->toIso8601String(), 'type' => 'datetime'] : null,
            $m->state === NotificationMessage::RETRYING && $m->available_at ? ['label' => 'Próxima tentativa', 'value' => $m->available_at->toIso8601String(), 'type' => 'datetime'] : null,
            $m->process_instance_id ? ['label' => 'Solicitação', 'value' => $numbers[$m->process_instance_id] ?? $m->process_instance_id, 'instanceId' => $m->process_instance_id] : null,
        ]));
        $outcomeLabel = ['sent' => 'enviado', 'retry' => 'falhou — nova tentativa agendada', 'failed' => 'falhou — sem novas tentativas', 'skipped' => 'descartado', 'manual_retry' => 'reprocessamento manual'];

        return [
            'id' => 'msg:'.$m->id, 'at' => ($m->created_at ?? now())->toIso8601String(), 'kind' => 'delivery', 'subsystem' => 'email',
            'level' => $level, 'title' => (self::EVENT_LABELS[$m->event] ?? $m->event).' — '.mb_strtolower(self::STATE_LABELS[$m->state] ?? $m->state),
            'summary' => 'Para '.$to.' · “'.mb_substr($m->subject, 0, 140).'”', 'actor' => 'worker de notificações',
            'code' => $m->error_code, 'explain' => in_array($m->state, [NotificationMessage::SENT, NotificationMessage::QUEUED, NotificationMessage::SENDING], true) ? null : IntegrationErrors::explain($m->error_code, 'email'),
            'durationMs' => $m->sent_at && $m->created_at ? (int) $m->created_at->diffInMilliseconds($m->sent_at) : null, 'correlationId' => $m->correlation_id,
            'steps' => array_map(fn ($a) => [
                'name' => (($a['outcome'] ?? '') === 'manual_retry' ? 'Reprocessamento manual' : 'Tentativa '.($a['attempt'] ?? '?')).' — '.($outcomeLabel[$a['outcome'] ?? ''] ?? ($a['outcome'] ?? '')),
                'ok' => ($a['outcome'] ?? '') === 'sent', 'level' => match ($a['outcome'] ?? '') {
                    'sent' => 'success', 'failed' => 'error', 'retry', 'skipped' => 'warning', default => 'info'
                },
                'code' => $a['code'] ?? null, 'message' => $a['message'] ?? null, 'at' => $a['at'] ?? null, 'durationMs' => $a['durationMs'] ?? null,
                'detail' => implode(' · ', array_filter([
                    isset($a['provider']) ? (EmailSettings::PROVIDERS[$a['provider']] ?? $a['provider']) : null,
                    isset($a['providerMessageId']) ? 'Message-ID '.$a['providerMessageId'] : null,
                    isset($a['nextAttemptAt']) ? 'próxima tentativa '.$a['nextAttemptAt'] : null,
                    $a['reason'] ?? null, isset($a['by']) ? 'por '.$a['by'] : null,
                ])) ?: null,
                'explain' => in_array($a['outcome'] ?? '', ['retry', 'failed', 'skipped'], true) ? IntegrationErrors::explain($a['code'] ?? null, 'email') : null,
            ], $m->attempt_log ?? []),
            'facts' => $facts,
        ];
    }

    /** @return array<string,mixed> */
    private function stats(string $area, ?Carbon $since): array
    {
        $since ??= now()->subDays(30);
        $keys = self::SETTINGS[$area];
        $tests = SettingValidation::whereIn('setting_key', $keys)->where('created_at', '>=', $since)->selectRaw('ok, count(*) as total')->groupBy('ok')->pluck('total', 'ok')->all();
        $out = ['since' => $since->toIso8601String(), 'testsOk' => (int) ($tests[1] ?? $tests['1'] ?? 0), 'testsFailed' => (int) ($tests[0] ?? $tests['0'] ?? 0)];
        if ($area === 'email') {
            $out['deliveries'] = NotificationMessage::where('created_at', '>=', $since)->selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state')->map(fn ($v) => (int) $v)->all();
        } elseif ($area === 'entra') {
            $out['runs'] = DirectorySyncRun::where('created_at', '>=', $since)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v)->all();
            $out['logins'] = AuditEvent::where('action', 'auth.login')->where('data->method', 'entra')->where('occurred_at', '>=', $since)->count();
            $out['rejectedTokens'] = AuditEvent::where('action', 'auth.token_rejected')->where('occurred_at', '>=', $since)->count();
        }

        return $out;
    }

    private static function since(?string $since): ?Carbon
    {
        return match ($since) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            default => null,
        };
    }

    private static function subsystem(string $settingKey): string
    {
        return match ($settingKey) {
            'entra.auth' => 'login',
            'entra.directory' => 'directory',
            'attachments.storage' => 'storage',
            default => 'email',
        };
    }

    private static function scalar(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? 'sim' : 'não';
        }

        return is_scalar($v) ? mb_substr((string) $v, 0, 300) : mb_substr((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0, 300);
    }
}
