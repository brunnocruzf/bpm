<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Attachment;
use App\Models\TaskProjection;
use App\Runtime\AttachmentService;
use App\Support\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    public function url(string $id): JsonResponse
    {
        $attachment = Attachment::find($id);
        if (! $attachment || ! $this->service->canDownload($this->user(), $attachment)) {
            throw ApiProblem::notFound('Anexo não encontrado ou sem permissão.');
        }

        return response()->json(['url' => $this->service->signedUrl($attachment, $this->user()), 'name' => $attachment->original_name]);
    }

    /** Rota assinada (sem bearer): assinatura + expiração validadas pelo middleware "signed". */
    public function download(Attachment $attachment): StreamedResponse
    {
        abort_if($attachment->av_status === 'infected', 403);
        $disk = Storage::disk($attachment->disk);
        abort_unless($disk->exists($attachment->object_key), 404);

        return $disk->download($attachment->object_key, $attachment->original_name, [
            'Content-Type' => $attachment->content_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
