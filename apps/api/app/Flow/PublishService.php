<?php

namespace App\Flow;

use App\Engine\EngineCommandDispatcher;
use App\Models\EngineCommand;
use App\Models\ProcessDefinitionVersion;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use App\Support\Correlation;
use Illuminate\Support\Facades\DB;

/**
 * Publicação: valida, congela BPMN XML + etapas + schemas (hash), e agenda o deploy
 * no Flowable via outbox. A versão só aceita instâncias após confirmação do deploy.
 */
class PublishService
{
    public function __construct(
        private readonly FlowValidator $validator,
        private readonly BpmnGenerator $generator,
        private readonly EngineCommandDispatcher $dispatcher,
    ) {}

    /** @return list<array<string,mixed>> */
    public function validate(ProcessDefinitionVersion $version): array
    {
        $problems = $this->validator->validate(FlowGraph::load($version));
        $version->forceFill(['validation_result' => ['problems' => $problems, 'at' => now('UTC')->toIso8601String()]])->save();

        return $problems;
    }

    public function publish(ProcessDefinitionVersion $version, User $user): ProcessDefinitionVersion
    {
        StageService::assertDraft($version);
        if (config('bpm.publish.require_distinct_publisher') && $version->updated_by === $user->id) {
            throw ApiProblem::forbidden('A política exige que a publicação seja feita por usuário diferente do autor do rascunho.');
        }

        $graph = FlowGraph::load($version);
        $problems = $this->validator->validate($graph);
        if (FlowValidator::hasErrors($problems)) {
            throw new ApiProblem(422, 'Não foi possível publicar', 'Corrija os problemas apontados antes de publicar.', 'validation_failed', [], ['problems' => $problems]);
        }

        $xml = $this->generator->generate($graph);
        $formHashes = [];
        foreach ($graph->ordered() as $stage) {
            if ($stage->form) {
                $formHashes[$stage->form->form_key] = $stage->form->hash ?? '';
            }
        }
        ksort($formHashes);
        $hash = hash('sha256', $xml.json_encode($formHashes));

        $command = DB::transaction(function () use ($version, $user, $xml, $hash, $problems) {
            $catalog = $version->catalog;
            $version->forms()->update(['status' => 'published']);
            $version->forceFill([
                'status' => ProcessDefinitionVersion::STATUS_PUBLISHED,
                'bpmn_xml' => $xml,
                'hash' => $hash,
                'validation_result' => ['problems' => $problems, 'at' => now('UTC')->toIso8601String()],
                'published_by' => $user->id,
                'published_at' => now('UTC'),
            ])->save();

            // Versões anteriores deixam de aceitar novas instâncias; as em andamento continuam na versão original.
            ProcessDefinitionVersion::where('catalog_id', $catalog->id)
                ->where('id', '!=', $version->id)
                ->where('status', ProcessDefinitionVersion::STATUS_PUBLISHED)
                ->update(['status' => ProcessDefinitionVersion::STATUS_RETIRED]);
            $catalog->update(['current_published_version_id' => $version->id]);

            $event = AuditLogger::record('version.published', 'process_definition_version', $version->id, ['key' => $catalog->key, 'version' => $version->version, 'hash' => $hash], null, $user, context: ['object_name' => $catalog->name]);

            return EngineCommand::create([
                'causation_id' => $event->id,
                'command' => EngineCommand::DEPLOY,
                'idempotency_key' => 'deploy:'.$version->id,
                'definition_version_id' => $version->id,
                'payload' => [
                    'deploymentName' => substr("{$catalog->key}:v{$version->version}:".substr($hash, 0, 12), 0, 120),
                    'fileName' => "{$catalog->key}.bpmn20.xml",
                ],
                'state' => EngineCommand::PENDING,
                'correlation_id' => Correlation::id(),
                'available_at' => now(),
            ]);
        });

        $this->dispatcher->dispatch($command);

        return $version->fresh();
    }
}
