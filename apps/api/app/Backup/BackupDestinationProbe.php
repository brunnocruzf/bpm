<?php

namespace App\Backup;

use App\Storage\Azure\AzureBlobException;
use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * Teste REAL da pasta de backups no Azure: grava em blocos, lista, baixa por faixas conferindo o SHA-256 e apaga. É o mesmo
 * caminho dos backups, diferente do teste de Armazenamento de anexos, que usa um único PUT.
 */
class BackupDestinationProbe
{
    private const BLOCK_BYTES = 1024 * 1024;

    public function __construct(private readonly BackupStorage $storage) {}

    /** @return array<string,mixed> resultado no formato de SettingsRepository::recordValidation */
    public function run(): array
    {
        $started = microtime(true);
        $checks = [];
        $step = function (string $name, Closure $fn) use (&$checks): bool {
            $t = microtime(true);
            try {
                $fn();
                $checks[] = ['name' => $name, 'ok' => true, 'code' => null, 'message' => null, 'durationMs' => (int) round((microtime(true) - $t) * 1000)];

                return true;
            } catch (AzureBlobException $e) {
                $checks[] = ['name' => $name, 'ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage(), 'durationMs' => (int) round((microtime(true) - $t) * 1000)];
            } catch (BackupFailure $e) {
                $checks[] = ['name' => $name, 'ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage(), 'durationMs' => (int) round((microtime(true) - $t) * 1000)];
            } catch (Throwable) {
                $checks[] = ['name' => $name, 'ok' => false, 'code' => 'internal_error', 'message' => 'Falha inesperada no teste.', 'durationMs' => (int) round((microtime(true) - $t) * 1000)];
            }

            return false;
        };

        $relative = '.probe/'.Str::uuid().'.bin';
        $work = $this->storage->stagingDir('probe-'.Str::uuid());
        $source = "$work/source.bin";
        $copy = "$work/copy.bin";
        $written = false;
        // três blocos e meio: o último fica menor, como em um arquivo real
        file_put_contents($source, random_bytes((int) (self::BLOCK_BYTES * 3.5)));

        $ok = $step('Destino ativo em Armazenamento de anexos', fn () => $this->storage->target() ?? throw new BackupFailure('azure_unavailable', 'Ative o Azure Blob Storage em Armazenamento de anexos.'))
            && $step('Gravação em blocos', function () use ($relative, $source, &$written) {
                $this->storage->uploadFrom($source, $relative, self::BLOCK_BYTES);
                $written = true;
            })
            && $step('Listagem da pasta', function () use ($relative) {
                if (! collect($this->storage->azureList('.probe/'))->contains('name', $relative)) {
                    throw new BackupFailure('list_failed', 'O arquivo de prova não apareceu na listagem da pasta.');
                }
            })
            && $step('Leitura por faixas e integridade (SHA-256)', function () use ($relative, $source, $copy) {
                $this->storage->download($relative, $copy, self::BLOCK_BYTES);
                if (hash_file('sha256', $copy) !== hash_file('sha256', $source)) {
                    throw new AzureBlobException('integrity_mismatch', 'O conteúdo lido difere do gravado.');
                }
            })
            && $step('Exclusão do arquivo de prova', function () use ($relative, &$written) {
                $this->storage->azureDelete($relative);
                $written = $this->storage->azureHas($relative);
                if ($written) {
                    throw new AzureBlobException('storage_forbidden', 'O arquivo de prova não pôde ser excluído (a retenção precisa desta permissão).');
                }
            });

        if ($written) {
            try {
                $this->storage->azureDelete($relative);
            } catch (Throwable) {
                // limpeza best-effort: arquivo pequeno, com nome aleatório
            }
        }
        @unlink($source);
        @unlink($copy);
        @rmdir($work);
        $failed = collect($checks)->firstWhere('ok', false);

        return [
            'ok' => $ok, 'step' => 'connection', 'code' => $ok ? 'backup_destination_ok' : ($failed['code'] ?? 'backup_destination_failed'),
            'message' => $ok ? 'Gravação em blocos, listagem, leitura e exclusão verificadas.' : ($failed['message'] ?? 'Falha no teste.'),
            'checks' => $checks, 'durationMs' => (int) round((microtime(true) - $started) * 1000), 'provider' => 'azure_blob', 'complete' => true,
        ];
    }
}
