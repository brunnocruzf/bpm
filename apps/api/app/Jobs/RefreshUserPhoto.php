<?php

namespace App\Jobs;

use App\Audit\AuditContext;
use App\Identity\Graph\GraphException;
use App\Identity\UserPhotoService;
use App\Models\User;
use App\Support\Masker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Atualiza a foto de um usuário Entra (fila "directory"). Falhas só viram log com código; nunca afetam o login. */
class RefreshUserPhoto implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $userId) {}

    public function handle(UserPhotoService $photos): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            return;
        }
        try {
            AuditContext::as('worker:foto do diretório', fn () => $photos->refresh($user));
        } catch (GraphException $e) {
            Log::notice('Foto: atualização não concluída', ['code' => $e->errorCode, 'reason' => Masker::error($e)]);
        }
    }
}
