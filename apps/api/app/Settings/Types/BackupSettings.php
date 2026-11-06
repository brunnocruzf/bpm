<?php

namespace App\Settings\Types;

use App\Models\AppSetting;
use App\Models\StorageTarget;
use App\Models\User;
use App\Settings\SettingType;
use App\Support\ApiProblem;
use DateTimeZone;
use Illuminate\Support\Str;

/**
 * Backup e recuperação (ADR-0021): agendamentos, cópias no servidor, pasta no Azure e chave pública de recuperação.
 * A pasta fica no contêiner do destino ativo de Armazenamento de anexos, relativa ao prefixo dele. A chave pública só
 * muda pela confirmação em BackupController: ela não aceita entrada pelo PUT da configuração.
 */
class BackupSettings extends SettingType
{
    public const KEY = 'backups';

    public const DATABASE = 'database';

    public const DATA = 'data';

    public const FULL = 'full';

    public const SCOPES = [self::DATABASE => 'Banco', self::DATA => 'Dados', self::FULL => 'Aplicação inteira'];

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /** Pastas que os anexos e o teste do destino já usam dentro do prefixo. */
    public const RESERVED_FOLDERS = ['attachments', '.bpm-probe'];

    private const MAX_SCHEDULES = 10;

    /** @var array<string,mixed>|null */
    private ?array $pendingKey = null;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Backup e recuperação';
    }

    public function defaults(): array
    {
        return [
            'timezone' => 'America/Sao_Paulo',
            'localCopies' => 2,
            'azure' => ['enabled' => false, 'folder' => 'backups'],
            'schedules' => [],
            'recoveryKey' => null,
        ];
    }

    /** @param array<string,mixed> $key publicKey, fingerprint, confirmedAt e confirmedBy */
    public function withRecoveryKey(array $key): self
    {
        $this->pendingKey = $key;

        return $this;
    }

    public function normalize(array $input, array $current, User $by): array
    {
        $errors = [];
        $v = $current;
        if ($this->pendingKey !== null) {
            $v['recoveryKey'] = $this->pendingKey;
        }
        if (array_key_exists('timezone', $input)) {
            $zone = (string) $input['timezone'];
            if (! in_array($zone, DateTimeZone::listIdentifiers(), true)) {
                $errors['timezone'][] = 'Fuso horário inválido.';
            }
            $v['timezone'] = $zone;
        }
        if (array_key_exists('localCopies', $input)) {
            $copies = filter_var($input['localCopies'], FILTER_VALIDATE_INT);
            if ($copies === false || $copies < 1 || $copies > 30) {
                $errors['localCopies'][] = 'Informe de 1 a 30 cópias no servidor.';
            }
            $v['localCopies'] = (int) $copies;
        }
        $azure = (array) ($input['azure'] ?? []);
        if (array_key_exists('folder', $azure)) {
            $folder = trim((string) $azure['folder'], " /\t");
            if (! self::validFolder($folder)) {
                $errors['azure.folder'][] = 'Pasta: letras, números, ponto, hífen, sublinhado e barra (sem ".." nem "//"), fora de "attachments".';
            }
            $v['azure']['folder'] = $folder;
        }
        if (array_key_exists('enabled', $azure)) {
            $v['azure']['enabled'] = (bool) $azure['enabled'];
            if ($v['azure']['enabled'] && ! StorageTarget::where('status', StorageTarget::ACTIVE)->exists()) {
                $errors['azure.enabled'][] = 'Ative o Azure Blob Storage em Armazenamento de anexos antes de enviar backups para lá.';
            }
        }
        if (array_key_exists('schedules', $input)) {
            $v['schedules'] = $this->normalizeSchedules((array) $input['schedules'], (array) $current['schedules'], $errors);
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }

        return $v;
    }

    public static function validFolder(string $folder): bool
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-\/]{0,99}$/', $folder) !== 1 || str_contains($folder, '..') || str_contains($folder, '//')) {
            return false;
        }

        return ! in_array(explode('/', $folder)[0], self::RESERVED_FOLDERS, true);
    }

    /**
     * @param  array<mixed>  $list
     * @param  array<mixed>  $current
     * @param  array<string, array<int,string>>  $errors
     * @return list<array<string,mixed>>
     */
    private function normalizeSchedules(array $list, array $current, array &$errors): array
    {
        if (count($list) > self::MAX_SCHEDULES) {
            $errors['schedules'][] = 'Use no máximo '.self::MAX_SCHEDULES.' agendamentos.';

            return [];
        }
        $known = collect($current)->keyBy('id');
        $out = [];
        foreach (array_values($list) as $i => $row) {
            $row = (array) $row;
            $frequency = (string) ($row['frequency'] ?? '');
            $scope = (string) ($row['scope'] ?? '');
            $time = (string) ($row['time'] ?? '');
            $keep = filter_var($row['keep'] ?? null, FILTER_VALIDATE_INT);
            if (! in_array($frequency, self::FREQUENCIES, true)) {
                $errors["schedules.$i.frequency"][] = 'Escolha diário, semanal ou mensal.';
            }
            if (! array_key_exists($scope, self::SCOPES)) {
                $errors["schedules.$i.scope"][] = 'Escolha o que será copiado.';
            }
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                $errors["schedules.$i.time"][] = 'Informe o horário no formato HH:MM.';
            }
            if ($keep === false || $keep < 1 || $keep > 365) {
                $errors["schedules.$i.keep"][] = 'Guarde de 1 a 365 cópias.';
            }
            $weekday = filter_var($row['weekday'] ?? null, FILTER_VALIDATE_INT);
            if ($frequency === 'weekly' && ($weekday === false || $weekday < 0 || $weekday > 6)) {
                $errors["schedules.$i.weekday"][] = 'Escolha o dia da semana.';
            }
            $monthDay = $row['monthDay'] ?? null;
            $validDay = $monthDay === 'last' || (filter_var($monthDay, FILTER_VALIDATE_INT) !== false && (int) $monthDay >= 1 && (int) $monthDay <= 28);
            if ($frequency === 'monthly' && ! $validDay) {
                $errors["schedules.$i.monthDay"][] = 'Escolha um dia de 1 a 28, ou o último dia do mês.';
            }
            $id = isset($row['id']) && Str::isUuid((string) $row['id']) ? (string) $row['id'] : (string) Str::uuid();
            $out[] = [
                'id' => $id, 'enabled' => (bool) ($row['enabled'] ?? true), 'frequency' => $frequency, 'time' => $time,
                'weekday' => $frequency === 'weekly' ? (int) $weekday : null,
                'monthDay' => $frequency === 'monthly' ? ($monthDay === 'last' ? 'last' : (int) $monthDay) : null,
                'scope' => $scope, 'azure' => (bool) ($row['azure'] ?? false), 'keep' => (int) $keep, 'verify' => (bool) ($row['verify'] ?? false),
                // horário anterior à criação do agendamento nunca dispara
                'createdAt' => $known->get($id)['createdAt'] ?? now('UTC')->toIso8601String(),
            ];
        }

        return $out;
    }

    public function statusAfterSave(array $value): array
    {
        if (empty($value['recoveryKey'])) {
            return [AppSetting::INCOMPLETE, 'Gere e confirme a chave de recuperação.'];
        }
        if ($value['azure']['enabled'] ?? false) {
            return [AppSetting::TEST_PENDING, 'Teste o destino no Azure para confirmar a gravação em blocos.'];
        }

        return [AppSetting::CONFIGURED, null];
    }

    public function present(array $value): array
    {
        $target = StorageTarget::where('status', StorageTarget::ACTIVE)->first();
        $value['scopes'] = collect(self::SCOPES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values();
        $value['azureTarget'] = $target ? [
            'accountName' => $target->config['accountName'] ?? null, 'container' => $target->config['container'] ?? null, 'pathPrefix' => $target->config['pathPrefix'] ?? '',
            'emulator' => ($target->config['endpointMode'] ?? 'public') === 'emulator',
        ] : null;

        return $value;
    }
}
