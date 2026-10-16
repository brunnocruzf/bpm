<?php

namespace App\Storage;

use App\Integrations\IntegrationUnavailable;
use App\Integrations\Rest\TargetPolicy;
use App\Settings\SecretStore;
use App\Settings\Types\AttachmentStorageSettings;
use App\Storage\Azure\AzureBlobClient;
use App\Storage\Azure\AzureBlobException;
use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * Teste REAL do destino Azure Blob (nunca um sucesso fixo): política de rede, credencial, contêiner existente e privado,
 * e gravação → leitura com conferência de SHA-256 → exclusão de um arquivo de prova. Roda sobre a configuração salva.
 */
class StorageProbe
{
    public function __construct(private readonly SecretStore $secrets) {}

    /**
     * @param  array<string,mixed>  $cfg  valor salvo de attachments.storage
     * @return array<string,mixed> resultado no formato de SettingsRepository::recordValidation
     */
    public function run(array $cfg): array
    {
        $started = microtime(true);
        $checks = [];
        $a = (array) $cfg['azure'];
        $endpoint = AttachmentStorageSettings::endpoint($cfg);
        $step = function (string $name, Closure $fn) use (&$checks): bool {
            $t = microtime(true);
            try {
                $detail = $fn();
                $checks[] = ['name' => $name, 'ok' => true, 'code' => null, 'message' => null, 'detail' => is_string($detail) ? $detail : null, 'durationMs' => (int) round((microtime(true) - $t) * 1000)];

                return true;
            } catch (AzureBlobException $e) {
                $checks[] = ['name' => $name, 'ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage(), 'durationMs' => (int) round((microtime(true) - $t) * 1000)];
            } catch (IntegrationUnavailable $e) {
                $checks[] = ['name' => $name, 'ok' => false, 'code' => $e->reason, 'message' => $e->getMessage(), 'durationMs' => (int) round((microtime(true) - $t) * 1000)];
            } catch (Throwable) {
                $checks[] = ['name' => $name, 'ok' => false, 'code' => 'internal_error', 'message' => 'Falha inesperada no teste.', 'durationMs' => (int) round((microtime(true) - $t) * 1000)];
            }

            return false;
        };

        $key = null;
        $client = null;
        $written = null;
        $ok = $step('Destino permitido pela política de rede', function () use ($a, $endpoint) {
            if (($a['endpointMode'] ?? 'public') === 'emulator') {
                if (! AttachmentStorageSettings::emulatorAvailable()) {
                    throw new AzureBlobException('emulator_forbidden', 'O emulador do Azure Storage não é permitido neste ambiente.');
                }

                return 'Emulador (somente desenvolvimento): '.parse_url($endpoint, PHP_URL_HOST);
            }
            TargetPolicy::assertAllowed($endpoint, true);

            return parse_url($endpoint, PHP_URL_HOST);
        })
            && $step('Credencial', function () use (&$key, $a) {
                $key = $this->secrets->get($a['credentialRef'] ?? null);
                if ($key === null || base64_decode($key, true) === false) {
                    throw new AzureBlobException('credential_invalid', 'A chave da conta não pôde ser lida (ou não está em base64).');
                }
            })
            && $step('Contêiner existe e é privado', function () use (&$client, $key, $a, $endpoint, $cfg) {
                $client = new AzureBlobClient(AttachmentStorageSettings::account($cfg), (string) $key, $endpoint, (string) $a['container'],
                    (string) config('bpm.storage.azure.api_version'), (int) config('bpm.storage.azure.timeout', 30), (int) config('bpm.storage.azure.connect_timeout', 5));
                $props = $client->containerProperties();
                if ($props['publicAccess'] !== null && $props['publicAccess'] !== 'private') {
                    throw new AzureBlobException('container_public', 'O contêiner permite acesso público: os anexos exigem contêiner privado.');
                }
                $skew = $props['date'] ? abs(strtotime($props['date']) - time()) : 0;

                return $skew > 300 ? "Atenção: o relógio do servidor difere {$skew}s do Azure (acima de 15 min o Azure recusa as chamadas)." : null;
            })
            && $step('Gravação de arquivo de prova', function () use ($client, $a, &$written, &$payload) {
                $payload = random_bytes(1024);
                $prefix = trim((string) ($a['pathPrefix'] ?? ''), '/');
                $written = ($prefix !== '' ? $prefix.'/' : '').'.bpm-probe/'.Str::uuid().'.bin';
                $client->put($written, $payload, 'application/octet-stream', true);
            })
            && $step('Leitura e integridade (SHA-256)', function () use ($client, &$written, &$payload) {
                if (hash('sha256', $client->get($written)) !== hash('sha256', (string) $payload)) {
                    throw new AzureBlobException('integrity_mismatch', 'O conteúdo lido difere do gravado.');
                }
            })
            && $step('Exclusão do arquivo de prova', function () use ($client, &$written) {
                $client->delete($written);
                if ($client->head($written) !== null) {
                    throw new AzureBlobException('storage_forbidden', 'O arquivo de prova não pôde ser excluído (a limpeza de órfãos precisa desta permissão).');
                }
                $written = null;
            });

        if ($written !== null && $client !== null) {
            try {
                $client->delete($written);
            } catch (Throwable) {
                // limpeza best-effort: o arquivo de prova é pequeno e tem nome aleatório
            }
        }
        $failed = collect($checks)->firstWhere('ok', false);
        $label = $a['accountName'] !== '' ? $a['accountName'].'/'.$a['container'] : $a['container'];

        return [
            'ok' => $ok, 'step' => 'connection', 'code' => $ok ? 'storage_ok' : ($failed['code'] ?? 'storage_failed'),
            'message' => $ok ? 'Gravação, leitura e exclusão verificadas. Ative para os novos anexos usarem este destino.' : ($failed['message'] ?? 'Falha no teste.'),
            'checks' => $checks, 'durationMs' => (int) round((microtime(true) - $started) * 1000), 'provider' => 'azure_blob', 'complete' => true,
            'detail' => $ok ? "Verificado em $label" : null,
        ];
    }
}
