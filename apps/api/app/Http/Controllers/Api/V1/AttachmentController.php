<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Attachment;
use App\Models\TaskProjection;
use App\Runtime\AttachmentService;
use App\Storage\AttachmentStorage;
use App\Support\ApiProblem;
use App\Support\Masker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AttachmentController extends ApiController
{
    public function __construct(private readonly AttachmentService $service) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file'],
            'taskId' => ['nullable', 'uuid'],
            'fieldKey' => ['nullable', 'string', 'max:64'],
            'supplierActionId' => ['nullable', 'uuid'],
        ]);
        $task = $request->input('taskId') ? TaskProjection::with('stage')->find($request->input('taskId')) : null;
        if ($request->input('taskId') && ! $task) {
            throw ApiProblem::notFound('Tarefa não encontrada.');
        }
        $attachment = $this->service->upload($request->file('file'), $this->user(), $task, $request->input('fieldKey'), null);

        return response()->json([
            ...AttachmentService::present($attachment),
            'file' => AttachmentService::canonical($attachment),
        ], 201);
    }

    /** Gera URL assinada curta após autorização. */
    public function url(Request $request, string $id): JsonResponse
    {
        $request->validate(['disposition' => ['sometimes', 'in:inline,attachment']]);
        $attachment = Attachment::find($id);
        if (! $attachment || ! $this->service->canDownload($this->user(), $attachment)) {
            throw ApiProblem::notFound('Anexo não encontrado ou sem permissão.');
        }

        $disposition = $request->query('disposition', 'attachment');

        return response()->json([
            'url' => $this->service->signedUrl($attachment, $this->user(), $disposition),
            'name' => $attachment->original_name,
            'type' => $attachment->content_type,
            'previewable' => AttachmentService::canPreviewInline($attachment->content_type),
        ]);
    }

    /** Rota assinada (sem bearer): assinatura + expiração validadas pelo middleware "signed". */
    public function download(Request $request, Attachment $attachment): StreamedResponse
    {
        abort_if($attachment->av_status === 'infected', 403);
        try {
            $disk = app(AttachmentStorage::class)->disk($attachment->disk);
            $exists = $disk->exists($attachment->object_key);
        } catch (Throwable $e) {
            Log::warning('Anexo: armazenamento indisponível no download', ['disk' => $attachment->disk, 'reason' => Masker::error($e)]);
            abort(503, 'Armazenamento indisponível.');
        }
        abort_unless($exists, 404);

        $disposition = $request->query('disposition') === 'inline' && AttachmentService::canPreviewInline($attachment->content_type)
            ? 'inline'
            : 'attachment';

        return $disk->response($attachment->object_key, $attachment->original_name, [
            'Content-Type' => $attachment->content_type,
            'Content-Length' => (string) $attachment->size,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ], $disposition);
    }
}
