<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Authorization\Permissions;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\NotificationMessage;
use App\Notifications\NotificationSender;
use App\Support\ApiProblem;
use App\Support\Masker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Status de entrega/retry das notificações (dead-letter consultável e reprocessável). */
class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::PROCESSES_MANAGE);
        $q = NotificationMessage::query()->orderByDesc('created_at');
        if ($state = $request->query('state')) {
            $q->whereIn('state', explode(',', (string) $state));
        }
        if ($instance = $request->query('processInstanceId')) {
            $q->where('process_instance_id', (string) $instance);
        }
        if ($task = $request->query('taskId')) {
            $q->where('task_projection_id', (string) $task);
        }

        return response()->json([
            'data' => $q->limit(200)->get()->map(fn (NotificationMessage $m) => [
                'id' => $m->id, 'event' => $m->event, 'state' => $m->state, 'recipient' => $m->recipient, 'recipientName' => $m->recipient_name,
                'recipientHint' => Masker::emailHint($m->recipient_address), 'subject' => $m->subject, 'attempts' => $m->attempts, 'maxAttempts' => $m->max_attempts,
                'provider' => $m->provider, 'providerMessageId' => $m->provider_message_id, 'errorCode' => $m->error_code, 'error' => $m->last_error,
                'processInstanceId' => $m->process_instance_id, 'taskId' => $m->task_projection_id, 'correlationId' => $m->correlation_id,
                'createdAt' => $m->created_at?->toIso8601String(), 'sentAt' => $m->sent_at?->toIso8601String(), 'nextAttemptAt' => $m->state === NotificationMessage::RETRYING ? $m->available_at?->toIso8601String() : null,
            ]),
            'summary' => NotificationMessage::selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state'),
        ]);
    }

    public function retry(string $id, NotificationSender $sender): JsonResponse
    {
        Gate::authorize(Permissions::PROCESSES_MANAGE);
        $m = NotificationMessage::find($id) ?? throw ApiProblem::notFound();
        if (! in_array($m->state, [NotificationMessage::FAILED, NotificationMessage::RETRYING], true)) {
            throw ApiProblem::conflict('Somente mensagens com falha ou aguardando nova tentativa podem ser reprocessadas.', 'not_retryable');
        }
        $sender->retry($m, $this->user());

        return response()->json(['id' => $m->id, 'state' => $m->fresh()?->state]);
    }
}
