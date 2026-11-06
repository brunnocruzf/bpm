<?php

namespace App\Backup;

use Illuminate\Support\Carbon;

/**
 * Estado da aplicação durante backup e restauração (ADR-0021), em arquivo de um volume próprio: enquanto os bancos são
 * trocados a API continua respondendo sem tocar no banco. Só o agente escreve; os demais serviços leem. Arquivo ausente
 * significa normal.
 */
class RuntimeState
{
    public const NORMAL = 'normal';

    /** Segundos, no início do dump: o engine não recebe nada entre os snapshots dos dois bancos. */
    public const PAUSE = 'pause';

    public const MAINTENANCE = 'maintenance';

    /** Depois de restaurar: só administradores entram, e e-mails e integrações esperam a liberação. */
    public const SAFE = 'safe';

    private const FILE = 'runtime.json';

    private const BEAT_FILE = 'heartbeat';

    /** @return array<string,mixed> */
    public function read(): array
    {
        $raw = @file_get_contents($this->path(self::FILE));
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    public function mode(): string
    {
        return (string) ($this->read()['mode'] ?? self::NORMAL);
    }

    /** Outbox e reconciliador não falam com o engine. */
    public function holdsEngine(): bool
    {
        return in_array($this->mode(), [self::PAUSE, self::MAINTENANCE], true);
    }

    /** Service tasks esperam: um job reaberto pela restauração poderia repetir uma escrita externa. */
    public function holdsIntegrations(): bool
    {
        return $this->mode() !== self::NORMAL;
    }

    /** E-mails pendentes no backup esperam a decisão do administrador. */
    public function holdsNotifications(): bool
    {
        return in_array($this->mode(), [self::MAINTENANCE, self::SAFE], true);
    }

    public function holdsSchedule(): bool
    {
        return in_array($this->mode(), [self::MAINTENANCE, self::SAFE], true);
    }

    /** @param array<string,mixed> $patch chave null remove o campo */
    public function write(array $patch): void
    {
        $data = array_filter(array_replace($this->read(), $patch), fn ($v) => $v !== null);
        $dir = $this->dir();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        // renomear é atômico: quem lê nunca vê um arquivo pela metade
        $tmp = $this->path(self::FILE.'.'.getmypid().'.tmp');
        file_put_contents($tmp, (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        chmod($tmp, 0644);
        rename($tmp, $this->path(self::FILE));
    }

    public function beatFile(): string
    {
        return $this->path(self::BEAT_FILE);
    }

    public function lastBeat(): ?Carbon
    {
        $file = $this->beatFile();
        clearstatcache(true, $file);
        $time = is_file($file) ? filemtime($file) : false;

        return $time ? Carbon::createFromTimestampUTC($time) : null;
    }

    public function agentOnline(): bool
    {
        $beat = $this->lastBeat();

        return $beat !== null && $beat->diffInSeconds(now(), true) <= (int) config('bpm.backup.heartbeat_stale_seconds', 60);
    }

    /** Chave pública efêmera do agente, usada para cifrar a chave de recuperação até ele (base64). */
    public function sealKey(): ?string
    {
        $key = $this->read()['sealKey'] ?? null;

        return is_string($key) ? $key : null;
    }

    private function dir(): string
    {
        return rtrim((string) config('bpm.backup.state_dir'), '/\\');
    }

    private function path(string $name): string
    {
        return $this->dir().DIRECTORY_SEPARATOR.$name;
    }
}
