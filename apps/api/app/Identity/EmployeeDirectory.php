<?php

namespace App\Identity;

use App\Integrations\LookupAmbiguous;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Consulta de colaborador pela matrícula no cadastro de usuários do BPM (sincronizado do Entra:
 * matrícula = extensionAttribute1, gestor = vínculo "manager"). Não chama o Graph: lê só o banco local.
 *
 * A matrícula do Entra começa com o código da empresa (2 caracteres: 01 TMSA, 02 IMS). O colaborador
 * pode digitar a matrícula completa (014085) ou só o número (4085, zeros à esquerda ignorados).
 */
class EmployeeDirectory
{
    public const SOURCE = 'employee';

    public const COMPANIES = ['01' => 'TMSA', '02' => 'IMS'];

    public const FIELDS = [
        'name' => 'Nome do colaborador',
        'registrationNumber' => 'Matrícula completa (com empresa)',
        'company' => 'Empresa',
        'department' => 'Departamento',
        'managerName' => 'Gestor imediato',
        'managerEmail' => 'E-mail do gestor imediato',
        'userId' => 'ID do colaborador (referência interna)',
        'managerUserId' => 'ID do gestor imediato (referência interna)',
    ];

    /** Campos que alimentam a atribuição de etapas: ficam no servidor e nunca voltam ao navegador. */
    public const USER_REFS = ['userId', 'managerUserId'];

    /** @return array<string,mixed>|null */
    public function lookup(string $value): ?array
    {
        $value = trim($value);
        if ($value === '' || ! preg_match('/^[A-Za-z0-9.\-\/]{1,60}$/', $value)) {
            return null;
        }
        $base = User::query()->with('manager:id,name,email')->whereNotNull('registration_number');

        $matches = (clone $base)->where('registration_number', $value)->get();
        if ($matches->isEmpty()) {
            // ignora o código da empresa (2 primeiros caracteres) e zeros à esquerda do número
            $number = ltrim($value, '0');
            $matches = $number === '' ? collect() : (clone $base)->whereRaw("ltrim(substr(registration_number, 3), '0') = ?", [$number])->get();
        }
        $user = $this->single($matches, $value);

        return $user ? [
            'name' => $user->name,
            'registrationNumber' => $user->registration_number,
            'company' => self::company((string) $user->registration_number),
            'department' => $user->department,
            'managerName' => $user->manager?->name,
            'managerEmail' => $user->manager?->email,
            'userId' => $user->id,
            'managerUserId' => $user->manager_user_id,
        ] : null;
    }

    /**
     * Resultado que pode sair do servidor. Outras fontes passam intactas: lá "userId" é dado do sistema externo.
     *
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    public static function withoutUserRefs(string $source, array $result): array
    {
        return $source === self::SOURCE ? array_diff_key($result, array_flip(self::USER_REFS)) : $result;
    }

    public static function company(string $registration): ?string
    {
        return self::COMPANIES[substr($registration, 0, 2)] ?? null;
    }

    /** @param Collection<int, User> $matches */
    private function single(Collection $matches, string $value): ?User
    {
        if ($matches->count() <= 1) {
            return $matches->first();
        }
        // a mesma pessoa pode ter conta desativada e ativa: vale a ativa
        $active = $matches->filter(fn (User $u) => $u->is_active !== false && $u->account_enabled !== false)->values();
        if ($active->count() === 1) {
            return $active->first();
        }
        $options = $matches->map(fn (User $u) => $u->registration_number.(($c = self::company((string) $u->registration_number)) ? " ($c)" : ''))->unique()->sort()->implode(', ');

        throw new LookupAmbiguous("A matrícula \"$value\" corresponde a mais de um colaborador ($options). Digite a matrícula completa, com o código da empresa (01 TMSA, 02 IMS).");
    }
}
