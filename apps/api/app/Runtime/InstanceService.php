<?php

namespace App\Runtime;

use App\Engine\EngineCommandDispatcher;
use App\Flow\FlowGraph;
use App\Forms\SubmissionContext;
use App\Forms\SubmissionProcessor;
use App\Models\EngineCommand;
use App\Models\FormSubmission;
use App\Models\ProcessCatalog;
use App\Models\ProcessInstance;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use App\Support\Correlation;
use Illuminate\Support\Facades\DB;

/**
 * Abertura de solicitação: grava instância (número sequencial/ano), submissão de abertura
 * validada, auditoria e comando START no outbox — tudo na mesma transação local.
 * A interface mostra "Processando" até o engine confirmar.
 */
class InstanceService
{
    public function __construct(
        private readonly SequenceService $sequences,
        private readonly SubmissionProcessor $processor,
        private readonly AttachmentService $attachments,
        private readonly EngineCommandDispatcher $dispatcher,
    ) {}

    /** @param array<string,mixed> $data */
    public function start(User $user, string $flowKey, array $data): ProcessInstance
    {
        $catalog = ProcessCatalog::where('key', $flowKey)->first() ?? throw ApiProblem::notFound('Fluxo não encontrado.');
        $version = $catalog->publishedVersion;
        if (! Access::canStart($user, $catalog)) {
            throw ApiProblem::forbidden('Você não pode iniciar este fluxo.');
        }
        if (! $version) {
            throw ApiProblem::conflict('Este fluxo ainda não possui versão publicada.', 'not_published');
        }
        if (! $version->isDeployed()) {
            throw ApiProblem::conflict('A versão publicada ainda está sendo implantada no engine. Tente novamente em instantes.', 'deploy_pending');
        }

        $graph = FlowGraph::load($version);
        $opening = $graph->openingStage();

        [$instance, $command] = DB::transaction(function () use ($user, $catalog, $version, $opening, $data) {
            $seq = $this->sequences->next('instance:'.$catalog->key);
            $number = $this->sequences->format($catalog->instance_prefix, $seq['value'], $seq['year']);

            $instance = ProcessInstance::create([
                'catalog_id' => $catalog->id,
                'definition_version_id' => $version->id,
                'number' => $number,
                'sequence_year' => $seq['year'],
                'sequence_value' => $seq['value'],
                'requester_id' => $user->id,
                'requester_name' => $user->name,
                'requester_area' => $user->area,
                'status' => ProcessInstance::STARTING,
                'data' => [],
                'started_at' => now('UTC'),
            ]);

            $variables = [];
            if ($opening && $opening->form) {
                $ctx = new SubmissionContext(
                    user: $user,
                    instance: $instance,
                    contextData: [],
                    instanceInfo: ['number' => $number, 'requesterName' => $user->name, 'requesterArea' => $user->area, 'startedAt' => $instance->started_at->toIso8601String()],
                    resolveAttachment: $this->attachments->resolver($user, $instance),
                );
                $processed = $this->processor->process($opening->form->schema, $data, $ctx);
                FormSubmission::create([
                    'process_instance_id' => $instance->id,
                    'stage_version_id' => $opening->id,
                    'form_schema_version_id' => $opening->form->id,
                    'form_schema_hash' => $opening->form->hash,
                    'payload' => $processed->data,
                    'metadata' => $processed->metadata,
                    'author_id' => $user->id,
                    'author_name' => $user->name,
                    'revision' => 1,
                    'created_at' => now('UTC'),
                ]);
                $instance->update(['data' => $processed->contextPatch()]);
                $this->attachments->linkToInstance($instance, $processed->data);
                $variables = $processed->engineVariables();
            }

            $event = AuditLogger::record('instance.created', 'process_instance', $instance->id, ['number' => $number, 'flow' => $catalog->key, 'version' => $version->version], $instance->id, context: ['object_name' => $catalog->name]);

            $command = EngineCommand::create([
                'causation_id' => $event->id,
                'command' => EngineCommand::START,
                'idempotency_key' => 'start:'.$instance->id,
                'process_instance_id' => $instance->id,
                'payload' => ['variables' => $variables],
                'state' => EngineCommand::PENDING,
                'correlation_id' => Correlation::id(),
                'available_at' => now(),
            ]);

            return [$instance, $command];
        });

        $this->dispatcher->dispatch($command);

        return $instance->fresh();
    }
}
