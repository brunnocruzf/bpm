<?php

namespace App\Identity;

use App\Identity\Graph\GraphClient;
use App\Identity\Graph\GraphException;
use App\Jobs\RefreshUserPhoto;
use App\Models\User;
use App\Models\UserPhoto;
use App\Support\Masker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Foto de perfil do Entra ID (miniatura quadrada em WebP, disco privado). Toda chamada ao Graph acontece
 * aqui (app/Identity), em job ou comando — nunca no request de um usuário. A mudança é detectada pelo
 *
 * @odata.mediaEtag; só então os bytes são baixados. Falha de foto nunca afeta o sync nem o login.
 */
class UserPhotoService
{
    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public const REMOVED = 'removed';

    public const SKIPPED = 'skipped';

    private const ALLOWED_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    public function __construct(private readonly DirectorySyncService $sync) {}

    /** Recurso habilitado: sync ligado e configurado, com a opção de fotos ativa. */
    public function enabled(?array $cfg = null): bool
    {
        $cfg ??= $this->sync->config();

        return (bool) ($cfg['enabled'] ?? false) && (bool) ($cfg['photos']['enabled'] ?? true) && $this->sync->type()->missing($cfg) === [];
    }

    /** @param array<string,mixed> $cfg */
    public function eligible(User $user, array $cfg): bool
    {
        return $user->auth_source === User::SOURCE_ENTRA && $user->entra_oid && $user->is_active !== false
            && $user->entra_tid === $this->sync->tenant($cfg);
    }

    public function version(User $user): ?string
    {
        return UserPhoto::where('user_id', $user->id)->where('status', UserPhoto::PRESENT)->first()?->version();
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<string, string> user_id => versão, só das fotos servíveis
     */
    public function versions(Collection $users): array
    {
        if ($users->isEmpty() || ! $this->enabled()) {
            return [];
        }
        $out = [];
        foreach (UserPhoto::whereIn('user_id', $users->pluck('id')->all())->where('status', UserPhoto::PRESENT)->get(['user_id', 'sha256', 'status']) as $p) {
            $out[$p->user_id] = (string) $p->version();
        }

        return $out;
    }

    /** Enfileira a atualização (login/ação do admin). Só despacha; não chama o Graph. */
    public function queueRefresh(User $user, bool $force = false): bool
    {
        try {
            $cfg = $this->sync->config();
            if (! $this->enabled($cfg) || ! $this->eligible($user, $cfg)) {
                return false;
            }
            $checked = UserPhoto::where('user_id', $user->id)->value('checked_at');
            if (! $force && $checked && now()->parse($checked)->gt(now()->subHours((int) config('bpm.directory.photos.login_check_hours', 1)))) {
                return false;
            }
            if (! Cache::add('bpm:photo-refresh:'.$user->id, 1, 600)) {
                return false;
            }
            RefreshUserPhoto::dispatch($user->id)->onQueue('directory');

            return true;
        } catch (Throwable $e) {
            Log::notice('Foto: não foi possível enfileirar a atualização', ['reason' => Masker::error($e)]);

            return false;
        }
    }

    /**
     * Atualiza a foto de um usuário. Erros de limite/permissão/credencial sobem para quem chamou decidir (lote para).
     *
     * @throws GraphException
     */
    public function refresh(User $user, ?GraphClient $client = null): string
    {
        $cfg = $this->sync->config();
        if (! $this->enabled($cfg) || ! $this->eligible($user, $cfg)) {
            return self::SKIPPED;
        }
        $lock = Cache::lock('bpm:user-photo:'.$user->id, 120);
        if (! $lock->get()) {
            return self::SKIPPED;
        }
        try {
            $graph = new EntraGraphDirectorySync($client ?? $this->sync->client($cfg));
            $row = UserPhoto::firstOrNew(['user_id' => $user->id]);
            try {
                $meta = $graph->userPhotoMeta((string) $user->entra_oid);
            } catch (GraphException $e) {
                if ($e->errorCode === 'not_found') {
                    return $this->markAbsent($row);
                }
                throw $e;
            }
            $etag = (string) ($meta['@odata.mediaEtag'] ?? '');
            if ($etag === '' || (int) ($meta['width'] ?? 2) <= 1) {
                return $this->markAbsent($row); // o Graph devolve um GIF 1x1 sem etag quando não há foto
            }
            if ($row->exists && $row->status === UserPhoto::PRESENT && $row->graph_etag === $etag) {
                $row->forceFill(['checked_at' => now(), 'error_code' => null])->save();

                return self::UNCHANGED;
            }
            try {
                $download = $graph->userPhoto((string) $user->entra_oid, (int) config('bpm.directory.photos.max_bytes'));
            } catch (GraphException $e) {
                if ($e->errorCode === 'not_found') {
                    return $this->markAbsent($row);
                }
                if ($e->errorCode === 'photo_too_large') {
                    return $this->markError($row, 'photo_too_large');
                }
                throw $e;
            }
            $thumb = $this->thumbnail($download['bytes']);
            if ($thumb === null) {
                return $this->markError($row, 'photo_invalid');
            }
            $this->store($row, $user, $thumb, $etag);

            return self::UPDATED;
        } finally {
            $lock->release();
        }
    }

    /**
     * Rodada periódica: usuários ativos vistos recentemente e com a verificação vencida. Para ao primeiro erro
     * de limite/permissão/credencial.
     *
     * @return array<string,int>
     */
    public function refreshBatch(?int $limit = null): array
    {
        $totals = ['checked' => 0, 'updated' => 0, 'unchanged' => 0, 'removed' => 0, 'skipped' => 0, 'errors' => 0];
        $cfg = $this->sync->config();
        if (! $this->enabled($cfg)) {
            return $totals;
        }
        $client = $this->sync->client($cfg);
        $users = User::query()->leftJoin('user_photo', 'user_photo.user_id', '=', 'users.id')
            ->where('users.auth_source', User::SOURCE_ENTRA)->where('users.is_active', true)->whereNotNull('users.entra_oid')
            ->where('users.entra_tid', $this->sync->tenant($cfg))
            ->where('users.last_seen_at', '>=', now()->subDays((int) config('bpm.directory.photos.active_within_days', 30)))
            ->where(fn ($w) => $w->whereNull('user_photo.checked_at')->orWhere('user_photo.checked_at', '<', now()->subHours((int) config('bpm.directory.photos.max_age_hours', 24))))
            ->orderByDesc('users.last_seen_at')->limit($limit ?? (int) config('bpm.directory.photos.batch', 200))->select('users.*')->get();
        foreach ($users as $user) {
            try {
                $result = $this->refresh($user, $client);
                $totals['checked']++;
                $totals[$result]++;
            } catch (GraphException $e) {
                $totals['errors']++;
                Log::notice('Foto: rodada interrompida', ['code' => $e->errorCode]);
                if (in_array($e->errorCode, ['throttled', 'consent_missing', 'credential_invalid', 'timeout'], true)) {
                    break;
                }
            }
        }

        return $totals;
    }

    /** Apaga fotos de usuários inativos/removidos, de quem deixou de ser Entra e todas, se o recurso estiver desligado. */
    public function purgeUnneeded(): int
    {
        $all = ! $this->enabled();
        $query = UserPhoto::query()->whereNotNull('object_key');
        if (! $all) {
            $query->whereIn('user_id', User::where(fn ($w) => $w->where('is_active', false)->orWhere('auth_source', '!=', User::SOURCE_ENTRA))->select('id'));
        }
        $n = 0;
        foreach ($query->get() as $photo) {
            $this->deleteObject($photo);
            $photo->forceFill(['disk' => null, 'object_key' => null, 'content_type' => null, 'size' => null, 'width' => null, 'height' => null, 'sha256' => null, 'graph_etag' => null, 'status' => UserPhoto::ABSENT, 'checked_at' => null])->save();
            $n++;
        }

        return $n;
    }

    /** @return array{bytes:string, contentType:string}|null */
    public function contents(UserPhoto $photo): ?array
    {
        if ($photo->status !== UserPhoto::PRESENT || ! $photo->disk || ! $photo->object_key) {
            return null;
        }
        try {
            $bytes = Storage::disk($photo->disk)->get($photo->object_key);
        } catch (Throwable $e) {
            // disco S3 configurado com "throw" => true: falta do objeto vira exceção, não null; trata como "sem foto"
            Log::notice('Foto: objeto ausente/ilegível no storage', ['reason' => Masker::error($e)]);

            return null;
        }

        return $bytes === null ? null : ['bytes' => $bytes, 'contentType' => (string) $photo->content_type];
    }

    private function markAbsent(UserPhoto $row): string
    {
        $had = $row->exists && $row->status === UserPhoto::PRESENT;
        $this->deleteObject($row);
        $row->forceFill(['disk' => null, 'object_key' => null, 'content_type' => null, 'size' => null, 'width' => null, 'height' => null, 'sha256' => null, 'graph_etag' => null, 'status' => UserPhoto::ABSENT, 'error_code' => null, 'checked_at' => now()])->save();

        return $had ? self::REMOVED : self::UNCHANGED;
    }

    private function markError(UserPhoto $row, string $code): string
    {
        $row->forceFill(['status' => $row->status === UserPhoto::PRESENT ? UserPhoto::PRESENT : UserPhoto::ERROR, 'error_code' => $code, 'checked_at' => now()])->save();

        return self::SKIPPED;
    }

    private function deleteObject(UserPhoto $row): void
    {
        if ($row->disk && $row->object_key) {
            try {
                Storage::disk($row->disk)->delete($row->object_key);
            } catch (Throwable $e) {
                Log::notice('Foto: não foi possível apagar o objeto antigo', ['reason' => Masker::error($e)]);
            }
        }
    }

    private function store(UserPhoto $row, User $user, string $webp, string $etag): void
    {
        $disk = (string) (config('bpm.directory.photos.disk') ?: config('bpm.attachments.disk'));
        $key = 'user-photos/'.$user->id.'/'.Str::uuid().'.webp';
        Storage::disk($disk)->put($key, $webp);
        $size = (int) config('bpm.directory.photos.size', 128);
        $old = $row->replicate();
        $old->disk = $row->disk;
        $old->object_key = $row->object_key;
        $row->forceFill(['disk' => $disk, 'object_key' => $key, 'content_type' => 'image/webp', 'size' => strlen($webp), 'width' => $size, 'height' => $size,
            'sha256' => hash('sha256', $webp), 'graph_etag' => $etag, 'status' => UserPhoto::PRESENT, 'error_code' => null, 'checked_at' => now(), 'fetched_at' => now()])->save();
        $this->deleteObject($old); // o objeto antigo só sai depois de trocar a linha
    }

    /** Valida pelo conteúdo e gera a miniatura quadrada (recorte central) em WebP; reencodar remove metadados. */
    private function thumbnail(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        $max = (int) config('bpm.directory.photos.max_dimension', 4096);
        if (! $info || ! in_array($info[2], self::ALLOWED_TYPES, true) || $info[0] < 1 || $info[1] < 1 || $info[0] > $max || $info[1] > $max) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if (! $src) {
            return null;
        }
        $side = min($info[0], $info[1]);
        $size = (int) config('bpm.directory.photos.size', 128);
        $dst = imagecreatetruecolor($size, $size);
        imagecopyresampled($dst, $src, 0, 0, intdiv($info[0] - $side, 2), intdiv($info[1] - $side, 2), $size, $size, $side, $side);
        ob_start();
        imagewebp($dst, null, 85);
        $out = (string) ob_get_clean();

        return $out !== '' ? $out : null;
    }
}
