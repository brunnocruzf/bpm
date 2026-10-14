<?php

namespace App\Runtime;

use App\Models\Attachment;
use App\Models\ProcessInstance;
use App\Models\TaskProjection;
use App\Models\User;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Anexos: metadados e permissões no SQL; binário no storage S3-compatível.
 * Valida extensão, MIME real, tamanho, antivírus; chave de objeto aleatória;
 * download apenas por URL assinada de curta duração após autorização.
 */
class AttachmentService
{
    public function __construct(private readonly VirusScanner $scanner) {}

    public function upload(UploadedFile $file, User $user, ?TaskProjection $task, ?string $fieldKey, ?string $supplierActionId = null): Attachment
    {
        if ($task && ! Access::canWork($user, $task)) {
            throw ApiProblem::forbidden('Você não pode anexar arquivos nesta tarefa.');
        }
        if (! $task && ! $user->hasRole('initiator')) {
            throw ApiProblem::forbidden();
        }

        $cfg = config('bpm.attachments');
        $errors = [];
        if (! $file->isValid()) {
            $errors['file'][] = 'Falha no envio do arquivo.';
        } else {
            $extension = strtolower($file->getClientOriginalExtension());
            $mime = (string) $file->getMimeType(); // detectado pelo conteúdo (finfo)
            if ($file->getSize() > $cfg['max_kb'] * 1024) {
                $errors['file'][] = 'Arquivo acima de '.round($cfg['max_kb'] / 1024).' MB.';
            }
            if (! in_array($extension, $cfg['allowed_extensions'], true)) {
                $errors['file'][] = "Extensão .$extension não permitida.";
            }
            if (! in_array($mime, $cfg['allowed_mimes'], true)) {
                $errors['file'][] = 'Tipo de arquivo não permitido.';
            }
            if ($mime === 'application/octet-stream' && ! in_array($extension, ['xlsx', 'xls', 'docx', 'doc', 'zip'], true)) {
                $errors['file'][] = 'Tipo de arquivo não reconhecido.';
            }
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        $av = $this->scanner->scan($file->getRealPath());
        if ($av === 'infected') {
            AuditLogger::record('attachment.rejected_infected', 'attachment', null, ['name' => $file->getClientOriginalName()], $task?->process_instance_id);
            throw ApiProblem::validation(['file' => ['O arquivo foi bloqueado pelo antivírus.']]);
        }

        $disk = (string) $cfg['disk'];
        $objectKey = 'attachments/'.now()->format('Y/m').'/'.Str::uuid()->toString();
        Storage::disk($disk)->putFileAs(dirname($objectKey), $file, basename($objectKey));

        $name = preg_replace('/[^\pL\pN ._()\-]/u', '_', $file->getClientOriginalName()) ?: 'arquivo';

        $attachment = Attachment::create([
            'disk' => $disk,
            'object_key' => $objectKey,
            'original_name' => mb_substr($name, 0, 255),
            'content_type' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'uploader_id' => $user->id,
            'process_instance_id' => $task?->process_instance_id,
            'task_projection_id' => $task?->id,
            'supplier_action_id' => $supplierActionId,
            'field_key' => $fieldKey && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/', $fieldKey) ? $fieldKey : null,
            'av_status' => $av,
        ]);
        AuditLogger::record('attachment.uploaded', 'attachment', $attachment->id, ['size' => $attachment->size, 'type' => $attachment->content_type, 'av' => $av], $task?->process_instance_id);

        return $attachment;
    }

    /**
     * Resolver usado na validação de submissões: só aceita anexos da própria instância
     * ou enviados pelo próprio usuário ainda sem vínculo (formulário de abertura).
     *
     * @return \Closure(string, string): (array<string,mixed>|null)
     */
    public function resolver(User $user, ?ProcessInstance $instance): \Closure
    {
        return function (string $id, string $fieldKey) use ($user, $instance): ?array {
            if (! Str::isUuid($id)) {
                return null;
            }
            $a = Attachment::find($id);
            if (! $a || $a->av_status === 'infected') {
                return null;
            }
            $allowed = ($instance && $a->process_instance_id === $instance->id)
                || ($a->process_instance_id === null && $a->uploader_id === $user->id);

            return $allowed ? self::canonical($a) : null;
        };
    }

    /** @param array<string,mixed> $data payload validado; vincula anexos referenciados (attachmentId) à instância */
    public function linkToInstance(ProcessInstance $instance, array $data): void
    {
        $ids = [];
        array_walk_recursive($data, function ($value, $key) use (&$ids) {
            if ($key === 'attachmentId' && is_string($value)) {
                $ids[] = $value;
            }
        });
        if ($ids) {
            Attachment::whereIn('id', $ids)->whereNull('process_instance_id')->update(['process_instance_id' => $instance->id]);
        }
    }

    public function canDownload(User $user, Attachment $a): bool
    {
        if ($a->av_status === 'infected') {
            return false;
        }
        if ($a->process_instance_id === null) {
            return $a->uploader_id === $user->id;
        }
        $instance = ProcessInstance::find($a->process_instance_id);

        return $instance && Access::canViewInstance($user, $instance);
    }

    public function signedUrl(Attachment $a, User $user): string
    {
        AuditLogger::record('attachment.download_url', 'attachment', $a->id, [], $a->process_instance_id, $user);

        return URL::temporarySignedRoute('attachments.download', now()->addMinutes((int) config('bpm.attachments.signed_url_minutes', 5)), ['attachment' => $a->id]);
    }

    /** @return array<string,mixed> */
    public static function canonical(Attachment $a): array
    {
        return [
            'storage' => 'tmsa',
            'name' => $a->id,
            'originalName' => $a->original_name,
            'size' => $a->size,
            'type' => $a->content_type,
            'data' => ['attachmentId' => $a->id],
        ];
    }

    /** @return array<string,mixed> */
    public static function present(Attachment $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->original_name,
            'size' => $a->size,
            'type' => $a->content_type,
            'fieldKey' => $a->field_key,
            'avStatus' => $a->av_status,
            'taskId' => $a->task_projection_id,
            'supplierActionId' => $a->supplier_action_id,
            'uploadedAt' => $a->created_at?->toIso8601String(),
        ];
    }
}
