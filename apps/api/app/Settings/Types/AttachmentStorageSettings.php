<?php

namespace App\Settings\Types;

use App\Integrations\IntegrationUnavailable;
use App\Integrations\Rest\TargetPolicy;
use App\Models\AppSetting;
use App\Models\StorageTarget;
use App\Models\User;
use App\Settings\SecretStore;
use App\Settings\SettingType;
use App\Support\ApiProblem;

/**
 * Onde gravar os anexos do Form.io: armazenamento da plataforma (padrão do ambiente) ou Azure Blob Storage com
 * Shared Key (conta + chave). A chave é somente escrita (SecretStore). Salvar não muda o destino: é preciso testar
 * (gravação, leitura e exclusão reais) e ativar. Anexos já gravados continuam no destino em que foram gravados.
 */
class AttachmentStorageSettings extends SettingType
{
    public const KEY = 'attachments.storage';

    public const PROVIDERS = ['platform' => 'Armazenamento da plataforma (padrão do ambiente)', 'azure_blob' => 'Azure Blob Storage'];

    public const CLOUDS = ['global' => 'core.windows.net', 'usgov' => 'core.usgovcloudapi.net', 'china' => 'core.chinacloudapi.cn'];

    public function __construct(private readonly SecretStore $secrets) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Armazenamento de anexos';
    }

    public function requiresActivation(): bool
    {
        return true;
    }

    public function defaults(): array
    {
        return [
            'provider' => 'platform',
            'azure' => [
                'endpointMode' => 'public', 'cloud' => 'global', 'accountName' => '', 'container' => '', 'pathPrefix' => '',
                'credentialRef' => null, 'approvalReference' => '',
                'checklist' => ['privateContainer' => false, 'softDelete' => false, 'dedicatedAccount' => false, 'hostDeclared' => false],
            ],
        ];
    }

    /** Endpoint base do Blob (sem segredos): https://{conta}.blob.{sufixo} ou o do emulador (somente ambiente). */
    public static function endpoint(array $value): string
    {
        $a = (array) ($value['azure'] ?? []);
        if (($a['endpointMode'] ?? 'public') === 'emulator') {
            return rtrim((string) config('bpm.storage.emulator.endpoint'), '/');
        }
        $suffix = self::CLOUDS[$a['cloud'] ?? 'global'] ?? self::CLOUDS['global'];

        return 'https://'.($a['accountName'] ?? '').'.blob.'.$suffix;
    }

    /** Conta usada na assinatura: no emulador vem do caminho do endpoint (/{conta}); no Azure, do campo. */
    public static function account(array $value): string
    {
        $a = (array) ($value['azure'] ?? []);

        return ($a['endpointMode'] ?? 'public') === 'emulator'
            ? trim((string) parse_url(self::endpoint($value), PHP_URL_PATH), '/')
            : (string) ($a['accountName'] ?? '');
    }

    public static function emulatorAvailable(): bool
    {
        return ! app()->environment('production') && (string) config('bpm.storage.emulator.endpoint') !== '';
    }

    /** Identidade estável do destino: o nome do disco gravado em attachment.disk (19 caracteres). */
    public static function diskName(array $value): string
    {
        $a = (array) $value['azure'];

        return 'azblob_'.substr(sha1(strtolower(self::endpoint($value)).'|'.self::account($value).'|'.($a['container'] ?? '').'|'.trim((string) ($a['pathPrefix'] ?? ''), '/')), 0, 12);
    }

    public function normalize(array $input, array $current, User $by): array
    {
        $errors = [];
        $v = $current;
        if (array_key_exists('provider', $input)) {
            $p = (string) $input['provider'];
            if (! array_key_exists($p, self::PROVIDERS)) {
                $errors['provider'][] = 'Destino inválido.';
            }
            $v['provider'] = $p;
        }
        $in = (array) ($input['azure'] ?? []);
        if (array_key_exists('endpointMode', $in)) {
            $mode = (string) $in['endpointMode'];
            if (! in_array($mode, ['public', 'emulator'], true)) {
                $errors['azure.endpointMode'][] = 'Modo de endpoint inválido.';
            } elseif ($mode === 'emulator' && ! self::emulatorAvailable()) {
                $errors['azure.endpointMode'][] = 'O emulador (Azurite) só existe em desenvolvimento/teste e é proibido em produção.';
            }
            $v['azure']['endpointMode'] = $mode;
        }
        if (array_key_exists('cloud', $in)) {
            if (! array_key_exists((string) $in['cloud'], self::CLOUDS)) {
                $errors['azure.cloud'][] = 'Nuvem inválida.';
            }
            $v['azure']['cloud'] = (string) $in['cloud'];
        }
        if (array_key_exists('accountName', $in)) {
            $name = strtolower(trim((string) $in['accountName']));
            if ($name !== '' && ! preg_match('/^[a-z0-9]{3,24}$/', $name)) {
                $errors['azure.accountName'][] = 'Nome da conta: 3 a 24 letras minúsculas e números.';
            }
            $v['azure']['accountName'] = $name;
        }
        if (array_key_exists('container', $in)) {
            $c = strtolower(trim((string) $in['container']));
            if ($c !== '' && (! preg_match('/^[a-z0-9](?:[a-z0-9]|-(?=[a-z0-9])){1,61}[a-z0-9]$/', $c))) {
                $errors['azure.container'][] = 'Contêiner: 3 a 63 caracteres (minúsculas, números e hífen; sem hífen duplo nem nas pontas).';
            }
            $v['azure']['container'] = $c;
        }
        if (array_key_exists('pathPrefix', $in)) {
            $prefix = trim((string) $in['pathPrefix'], " /\t");
            if ($prefix !== '' && (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-\/]{0,199}$/', $prefix) || str_contains($prefix, '..') || str_contains($prefix, '//'))) {
                $errors['azure.pathPrefix'][] = 'Prefixo: letras, números, ponto, hífen, sublinhado e barra (sem ".." nem "//").';
            }
            $v['azure']['pathPrefix'] = $prefix;
        }
        if (array_key_exists('approvalReference', $in)) {
            $v['azure']['approvalReference'] = mb_substr(trim(strip_tags((string) $in['approvalReference'])), 0, 120);
        }
        if (isset($in['checklist']) && is_array($in['checklist'])) {
            foreach (array_keys($this->defaults()['azure']['checklist']) as $item) {
                if (array_key_exists($item, $in['checklist'])) {
                    $v['azure']['checklist'][$item] = (bool) $in['checklist'][$item];
                }
            }
        }
        if (! empty($in['credentialFileRef'])) {
            if (! SecretStore::isValidFileRef((string) $in['credentialFileRef'])) {
                $errors['azure.credentialFileRef'][] = 'Use file:<nome> de um segredo montado.';
            } else {
                $this->releaseDraftSecret($v['azure']['credentialRef'] ?? null);
                $v['azure']['credentialRef'] = (string) $in['credentialFileRef'];
            }
        }
        if (! empty($in['accountKey'])) {
            $key = trim((string) $in['accountKey']);
            $raw = base64_decode($key, true);
            if ($raw === false || strlen($raw) < 16) {
                $errors['azure.accountKey'][] = 'A chave da conta deve estar em base64 (portal: Chaves de acesso).';
            } else {
                $v['azure']['credentialRef'] = $this->secrets->put('attachments.azure.account_key', $key, $by, $this->draftRef($v['azure']['credentialRef'] ?? null));
            }
        }

        if (! $errors && ($v['provider'] ?? 'platform') === 'azure_blob') {
            $this->validateEndpoint($v, $errors);
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return $v;
    }

    /** @param array<string,array<int,string>> $errors */
    private function validateEndpoint(array $v, array &$errors): void
    {
        $a = $v['azure'];
        if ($a['endpointMode'] === 'public' && $a['accountName'] === '') {
            return; // ainda incompleto: o estado mostra "Faltam: …"
        }
        $url = self::endpoint($v);
        if ($url === '') {
            $errors['azure.endpointMode'][] = 'Endpoint do emulador não configurado neste ambiente (BPM_AZURITE_BLOB_ENDPOINT).';

            return;
        }
        try {
            if ($a['endpointMode'] === 'public') {
                TargetPolicy::assertAllowed($url, false);
            }
        } catch (IntegrationUnavailable $e) {
            $errors['azure.accountName'][] = $e->getMessage();
        }
    }

    /** A referência em edição só é substituída no lugar se não pertencer a um destino já ativado (que guarda cópia própria). */
    private function draftRef(?string $ref): ?string
    {
        return $ref && str_starts_with($ref, 'db:') ? $ref : null;
    }

    private function releaseDraftSecret(?string $ref): void
    {
        $this->secrets->delete($this->draftRef($ref));
    }

    public function statusAfterSave(array $value): array
    {
        if (($value['provider'] ?? 'platform') !== 'azure_blob') {
            return [AppSetting::DISABLED, 'Anexos no armazenamento padrão da plataforma.'];
        }
        $missing = $this->missing($value);
        if ($missing) {
            return [AppSetting::INCOMPLETE, 'Faltam: '.implode(', ', $missing).'.'];
        }

        return [AppSetting::TEST_PENDING, 'Teste gravação, leitura e exclusão e ative para valer nos novos anexos.'];
    }

    /** @return list<string> */
    public function missing(array $v): array
    {
        $a = (array) $v['azure'];
        $missing = [];
        if (($a['endpointMode'] ?? 'public') === 'public' && empty($a['accountName'])) {
            $missing[] = 'nome da conta';
        }
        if (empty($a['container'])) {
            $missing[] = 'contêiner';
        }
        if (empty($a['credentialRef'])) {
            $missing[] = 'chave da conta';
        }
        if (empty($a['approvalReference'])) {
            $missing[] = 'referência da aprovação do uso de chave de conta';
        }

        return $missing;
    }

    public function present(array $value): array
    {
        $ref = $value['azure']['credentialRef'] ?? null;
        unset($value['azure']['credentialRef']);
        $value['azure']['credential'] = $this->secrets->describe($ref);
        $value['providers'] = collect(self::PROVIDERS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values();
        $value['emulatorAvailable'] = self::emulatorAvailable();
        $disk = (string) config('bpm.attachments.disk');
        $value['environment'] = ['disk' => $disk, 'driver' => (string) config("filesystems.disks.$disk.driver")];
        $active = StorageTarget::where('status', StorageTarget::ACTIVE)->first();
        $value['effective'] = $active
            ? ['provider' => 'azure_blob', 'accountName' => $active->config['accountName'] ?? null, 'container' => $active->config['container'] ?? null, 'pathPrefix' => $active->config['pathPrefix'] ?? '', 'emulator' => ($active->config['endpointMode'] ?? 'public') === 'emulator', 'activatedAt' => $active->activated_at?->toIso8601String()]
            : ['provider' => 'platform', 'accountName' => null, 'container' => null, 'pathPrefix' => '', 'emulator' => false, 'activatedAt' => null];

        return $value;
    }

    public function redactedForHistory(array $value): array
    {
        $value['azure']['credentialRef'] = ($value['azure']['credentialRef'] ?? null) ? '[referência de segredo]' : null;

        return $value;
    }
}
