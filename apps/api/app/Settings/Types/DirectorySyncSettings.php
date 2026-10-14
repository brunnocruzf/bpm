<?php

namespace App\Settings\Types;

use App\Identity\EntraAppTokenProvider;
use App\Identity\Graph\MockGraphClient;
use App\Models\AppSetting;
use App\Models\User;
use App\Settings\SecretStore;
use App\Settings\SettingType;
use App\Support\ApiProblem;

/**
 * Importação/sincronização de usuários e grupos (Graph app-only, somente leitura). Registro de
 * aplicativo SEPARADO do login. Credencial preferida: certificado ou workload identity federation;
 * client secret só com aprovação registrada. Segredos vão para o SecretStore (nunca no valor).
 */
class DirectorySyncSettings extends SettingType
{
    public const KEY = 'entra.directory';

    public const PERMISSIONS = [
        ['permission' => 'User.Read.All', 'type' => 'Application', 'why' => 'Ler usuários e o vínculo de manager (users/delta, users/{id}).'],
        ['permission' => 'Group.Read.All', 'type' => 'Application', 'why' => 'Ler grupos (selecionados ou todos) e membros (groups/delta com members). Avaliar GroupMember.Read.All na POC.'],
    ];

    public function __construct(private readonly SecretStore $secrets) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Microsoft Entra ID — importação de usuários e grupos';
    }

    public function defaults(): array
    {
        return [
            'enabled' => false,
            'transport' => 'graph',
            'tenantId' => '',
            'authorityHost' => 'login.microsoftonline.com',
            'clientId' => '',
            'credentialType' => 'certificate',
            'credentialRef' => null,
            'certificate' => null,
            'clientSecretApproval' => null,
            // groups = membros diretos dos grupos selecionados; all = todos os grupos e usuários do tenant
            'scope' => 'groups',
            'groups' => [],
            'includeGuests' => false,
            'includeDisabled' => false,
            'scheduleMinutes' => 0,
            'staleAfterHours' => 48,
            'checklist' => ['separateApp' => false, 'applicationPermissions' => false, 'adminConsent' => false, 'noWritePermissions' => false],
        ];
    }

    public function normalize(array $input, array $current, User $by): array
    {
        $guid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        $errors = [];
        $v = $current;

        foreach (['enabled', 'includeGuests', 'includeDisabled'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $v[$flag] = (bool) $input[$flag];
            }
        }
        if (array_key_exists('transport', $input)) {
            $t = (string) $input['transport'];
            if (! in_array($t, ['graph', 'mock'], true)) {
                $errors['transport'][] = 'Transporte inválido.';
            } elseif ($t === 'mock' && app()->environment('production')) {
                $errors['transport'][] = 'O diretório fictício (mock) é proibido em produção.';
            }
            $v['transport'] = $t;
        }
        foreach (['tenantId', 'clientId'] as $f) {
            if (array_key_exists($f, $input)) {
                $val = strtolower(trim((string) $input[$f]));
                if ($val !== '' && ! preg_match($guid, $val)) {
                    $errors[$f][] = 'Informe um GUID.';
                }
                $v[$f] = $val;
            }
        }
        if (array_key_exists('authorityHost', $input)) {
            $h = strtolower((string) $input['authorityHost']);
            if (! in_array($h, (array) config('bpm.directory.authority_hosts'), true)) {
                $errors['authorityHost'][] = 'Host de identidade não suportado.';
            }
            $v['authorityHost'] = $h;
        }
        if (array_key_exists('credentialType', $input)) {
            $ct = (string) $input['credentialType'];
            if (! in_array($ct, ['certificate', 'federated', 'client_secret'], true)) {
                $errors['credentialType'][] = 'Tipo de credencial inválido (identidade gerenciada não está disponível neste ambiente).';
            }
            if ($ct !== $v['credentialType']) {
                $this->secrets->delete($v['credentialRef']);
                $v['credentialRef'] = null;
                $v['certificate'] = null;
            }
            $v['credentialType'] = $ct;
        }
        if (array_key_exists('groups', $input)) {
            $groups = [];
            foreach ((array) $input['groups'] as $g) {
                $id = strtolower(trim((string) (is_array($g) ? ($g['id'] ?? '') : $g)));
                if (! preg_match($guid, $id)) {
                    $errors['groups'][] = "Object ID de grupo inválido: $id";

                    continue;
                }
                $groups[$id] = ['id' => $id, 'name' => is_array($g) ? mb_substr(strip_tags((string) ($g['name'] ?? '')), 0, 120) : ''];
            }
            if (count($groups) > 50) {
                $errors['groups'][] = 'No máximo 50 grupos (limite do filtro do delta query).';
            }
            $v['groups'] = array_values($groups);
        }
        if (array_key_exists('scope', $input)) {
            $scope = (string) $input['scope'];
            if (! in_array($scope, ['groups', 'all'], true)) {
                $errors['scope'][] = 'Escopo inválido.';
            } elseif ($scope === 'all' && ! self::allDirectory($current) && empty($input['confirmAllDirectory'])) {
                // ampliação deliberada: importa nome, e-mail e departamento de todo o tenant
                $errors['scope'][] = 'Confirme a importação de todo o diretório (todos os grupos e usuários do tenant).';
            }
            $v['scope'] = $scope;
        }
        if (! self::allDirectory($v) && ($v['transport'] ?? 'graph') === 'graph' && (array_key_exists('groups', $input) || array_key_exists('transport', $input) || array_key_exists('scope', $input))) {
            $fictitious = array_filter(array_column((array) ($v['groups'] ?? []), 'id'), fn ($id) => MockGraphClient::isFictitiousGroup((string) $id));
            if ($fictitious) {
                $errors['groups'][] = count($fictitious).' grupo(s) do diretório fictício não existem no tenant real: remova-os e informe os Object IDs dos grupos do Entra.';
            }
        }
        if (array_key_exists('scheduleMinutes', $input)) {
            $m = (int) $input['scheduleMinutes'];
            if (! in_array($m, [0, 60, 240, 720, 1440], true)) {
                $errors['scheduleMinutes'][] = 'Periodicidade inválida.';
            }
            $v['scheduleMinutes'] = $m;
        }
        if (array_key_exists('staleAfterHours', $input)) {
            $v['staleAfterHours'] = max(1, min(720, (int) $input['staleAfterHours']));
        }
        if (isset($input['checklist']) && is_array($input['checklist'])) {
            foreach (array_keys($this->defaults()['checklist']) as $item) {
                if (array_key_exists($item, $input['checklist'])) {
                    $v['checklist'][$item] = (bool) $input['checklist'][$item];
                }
            }
        }

        // Credencial (somente escrita). Nunca devolvida.
        $type = $v['credentialType'];
        if (! empty($input['credentialFileRef'])) {
            $ref = (string) $input['credentialFileRef'];
            if (! SecretStore::isValidFileRef($ref)) {
                $errors['credentialFileRef'][] = 'Referência deve ser file:<nome> de um segredo montado.';
            } else {
                $this->secrets->delete($v['credentialRef']);
                $v['credentialRef'] = $ref;
            }
        }
        if ($type === 'certificate' && ! empty($input['certificatePem'])) {
            $pem = (string) $input['certificatePem'];
            $info = EntraAppTokenProvider::certificateInfo($pem);
            $key = openssl_pkey_get_private($pem);
            if (! $info || ! $key || ! openssl_x509_check_private_key((string) (preg_match('/-----BEGIN CERTIFICATE-----[\s\S]+?-----END CERTIFICATE-----/', $pem, $m) ? $m[0] : ''), $key)) {
                $errors['certificatePem'][] = 'PEM deve conter o CERTIFICATE e a PRIVATE KEY correspondente (sem senha).';
            } elseif ($info['notAfter'] < time()) {
                $errors['certificatePem'][] = 'Certificado expirado.';
            } elseif (! $errors) {
                $v['credentialRef'] = $this->store('entra.directory.certificate', $pem, $by, $v['credentialRef']);
                $v['certificate'] = ['thumbprint' => $info['thumbprintSha1'], 'subject' => $info['subject'], 'notAfter' => date(DATE_ATOM, $info['notAfter'])];
            }
        }
        if ($type === 'client_secret' && ! empty($input['clientSecret'])) {
            $approval = trim((string) ($input['clientSecretApproval'] ?? $v['clientSecretApproval'] ?? ''));
            if (mb_strlen($approval) < 5) {
                $errors['clientSecretApproval'][] = 'Client secret exige referência da aprovação da organização (ex.: chamado/ADR).';
            } elseif (! $errors) {
                $v['credentialRef'] = $this->store('entra.directory.client_secret', (string) $input['clientSecret'], $by, $v['credentialRef']);
                $v['clientSecretApproval'] = mb_substr($approval, 0, 200);
            }
        }
        if ($type === 'federated' && ! empty($input['clientSecret'])) {
            $errors['credentialFileRef'][] = 'Workload identity federation usa o arquivo de token montado (file:<nome>), não um segredo.';
        }

        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return $v;
    }

    private function store(string $purpose, string $value, User $by, ?string $current): string
    {
        return $this->secrets->put($purpose, $value, $by, $current);
    }

    public function statusAfterSave(array $value): array
    {
        if (! $value['enabled']) {
            return [AppSetting::DISABLED, 'Sincronização desabilitada.'];
        }
        $missing = $this->missing($value);
        if ($missing) {
            return [AppSetting::INCOMPLETE, 'Faltam: '.implode(', ', $missing).'.'];
        }

        return [AppSetting::TEST_PENDING, 'Teste a conexão e execute uma prévia antes da primeira sincronização.'];
    }

    /** @return list<string> */
    public function missing(array $value): array
    {
        $missing = [];
        if (($value['transport'] ?? 'graph') === 'graph') {
            foreach (['tenantId' => 'Tenant ID', 'clientId' => 'Client ID do app de sincronização'] as $f => $label) {
                if (empty($value[$f])) {
                    $missing[] = $label;
                }
            }
            if (empty($value['credentialRef'])) {
                $missing[] = 'credencial';
            }
        }
        if (! self::allDirectory($value) && empty($value['groups'])) {
            $missing[] = 'grupos a sincronizar';
        }

        return $missing;
    }

    /** Escopo "todo o diretório": todos os grupos e usuários do tenant (a lista de grupos é ignorada). */
    public static function allDirectory(array $value): bool
    {
        return ($value['scope'] ?? 'groups') === 'all';
    }

    public function present(array $value): array
    {
        $ref = $value['credentialRef'] ?? null;
        unset($value['credentialRef']);
        $value['credential'] = $this->secrets->describe($ref);
        $value['permissions'] = self::PERMISSIONS;

        return $value;
    }

    public function redactedForHistory(array $value): array
    {
        $value['credentialRef'] = $value['credentialRef'] ? '[referência de segredo]' : null;

        return $value;
    }
}
