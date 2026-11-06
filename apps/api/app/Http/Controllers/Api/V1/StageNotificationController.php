<?php

namespace App\Http\Controllers\Api\V1;

use App\Authorization\Permissions;
use App\Flow\AssignmentRule;
use App\Models\BpmGroup;
use App\Models\ProcessStageVersion;
use App\Models\TaskProjection;
use App\Notifications\NotificationTemplate;
use App\Notifications\Notifier;
use App\Notifications\RecipientResolver;
use App\Settings\SettingsRepository;
use App\Settings\Types\BrandingSettings;
use App\Support\ApiProblem;
use App\Support\Masker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pré-visualização da notificação da etapa (texto com dados de exemplo) e, para administradores,
 * prévia de destinatários e envio de teste para o endereço informado (ambiente de teste).
 */
class StageNotificationController extends ApiController
{
    public function preview(Request $request, string $key, string $v, string $stageKey, RecipientResolver $resolver, SettingsRepository $settings): JsonResponse
    {
        if (! $this->canReadDefinitions()) {
            throw ApiProblem::forbidden();
        }
        $stage = $this->stage($key, $v, $stageKey);
        $cfg = (array) ($stage->configValue('notifyAssignee') ?? []);
        $template = NotificationTemplate::validate($request->query('subject') ?: ($cfg['subject'] ?? null), $request->query('body') ?: ($cfg['body'] ?? null));
        $vars = $this->sampleVars($stage, $settings);
        $admin = Permissions::allows($this->user(), Permissions::SETTINGS_MANAGE) || Permissions::allows($this->user(), Permissions::PROCESSES_MANAGE);

        // com regra de atribuição o responsável só existe na execução: a lista mostra a contingência
        $rule = AssignmentRule::of($stage);
        $group = $rule ? ($rule['fallbackGroup'] ?? null) : $stage->lane;
        $recipients = null;
        if ($admin) {
            $probe = new TaskProjection(['candidate_group' => $group, 'name' => $stage->name]);
            $recipients = array_map(fn ($r) => ['name' => $r['name'], 'hint' => Masker::emailHint($r['address']), 'kind' => str_starts_with($r['key'], 'group:') ? 'caixa do grupo' : 'membro'],
                $resolver->forTask($probe, (string) ($request->query('groupPolicy') ?: ($cfg['groupPolicy'] ?? 'members'))));
        }

        return response()->json([
            'enabled' => (bool) ($cfg['enabled'] ?? false),
            'subject' => NotificationTemplate::render($template['subject'], $vars, true),
            'body' => NotificationTemplate::render($template['body'], $vars),
            'placeholders' => collect(NotificationTemplate::PLACEHOLDERS)->map(fn ($label, $k) => ['key' => $k, 'label' => $label])->values(),
            'defaults' => ['subject' => NotificationTemplate::DEFAULT_SUBJECT, 'body' => NotificationTemplate::DEFAULT_BODY],
            'recipients' => $recipients,
            'assignment' => $rule ? ['mode' => $rule['mode'], 'fallbackGroup' => $group, 'fallbackGroupName' => $group ? (BpmGroup::where('key', $group)->value('name') ?? $group) : null] : null,
            'canTest' => Permissions::allows($this->user(), Permissions::SETTINGS_MANAGE),
        ]);
    }

    public function test(Request $request, string $key, string $v, string $stageKey, Notifier $notifier, SettingsRepository $settings): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $data = $request->validate(['to' => ['required', 'email:rfc', 'max:200']]);
        $stage = $this->stage($key, $v, $stageKey);
        $cfg = (array) ($stage->configValue('notifyAssignee') ?? []);
        $template = NotificationTemplate::validate($cfg['subject'] ?? null, $cfg['body'] ?? null);
        $vars = $this->sampleVars($stage, $settings);
        $message = $notifier->testEmail((string) $data['to'], $this->user(), '[TESTE] '.NotificationTemplate::render($template['subject'], $vars, true), NotificationTemplate::render($template['body'], $vars));

        return response()->json(['id' => $message->id, 'state' => $message->state], 202);
    }

    private function stage(string $key, string $v, string $stageKey): ProcessStageVersion
    {
        $version = $this->findVersion($this->findCatalog($key), $v);
        $stage = ProcessStageVersion::where('definition_version_id', $version->id)->where('stage_key', $stageKey)->first();
        if (! $stage || ! $stage->isHuman()) {
            throw ApiProblem::notFound('Etapa humana não encontrada.');
        }

        return $stage;
    }

    /** @return array<string,string> */
    private function sampleVars(ProcessStageVersion $stage, SettingsRepository $settings): array
    {
        $catalog = $stage->definitionVersion->catalog;
        $brand = $settings->value(app(BrandingSettings::class));

        return [
            'organizacao' => (string) $brand['orgName'],
            'fluxo' => $catalog->name,
            'protocolo' => $catalog->instance_prefix.'-000123/'.now()->format('y').' (exemplo)',
            'etapa' => $stage->name,
            'grupo' => AssignmentRule::of($stage) ? 'Responsável direto' : ($stage->lane ? (BpmGroup::where('key', $stage->lane)->value('name') ?? $stage->lane) : '—'),
            'responsavel' => 'Nome do responsável',
            'prazo' => $stage->configValue('slaDays') ? now()->addDays((int) $stage->configValue('slaDays'))->format('d/m/Y') : 'não definido',
            'link' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/tasks/(id-da-tarefa)',
        ];
    }
}
