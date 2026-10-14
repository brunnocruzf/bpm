<?php

namespace App\Console\Commands;

use App\Audit\AuditContext;
use App\Auth\AuthMethods;
use App\Auth\PasswordPolicy;
use App\Auth\SuperAdminAuth;
use App\Models\AuthSession;
use App\Models\LocalCredential;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gestão do SuperAdmin (acesso de emergência). Única forma de definir a senha: ela nunca passa pela
 * API, pela tela, pelo .env nem por argumento de linha de comando (histórico do shell) — somente
 * digitada (oculta), via stdin, arquivo de secret ou gerada aleatoriamente e exibida uma única vez.
 */
class SuperAdminCommand extends Command
{
    protected $signature = 'bpm:superadmin
        {action=status : set-password | status | unlock | disable | revoke-sessions}
        {--username=superadmin : Usuário do SuperAdmin}
        {--name=SuperAdmin — acesso de emergência : Nome exibido}
        {--generate : Gera uma senha forte aleatória e a exibe UMA única vez}
        {--stdin : Lê a senha da entrada padrão (automação)}
        {--password-file= : Lê a senha de um arquivo (ex.: Docker secret)}';

    protected $description = 'Define a senha, consulta, desbloqueia ou desativa o SuperAdmin (login de emergência)';

    private const ACTOR = 'console (bpm:superadmin)';

    public function handle(SuperAdminAuth $auth): int
    {
        $username = mb_strtolower(trim((string) $this->option('username')));
        if (! preg_match('/^[a-z][a-z0-9._-]{2,40}$/', $username)) {
            $this->error('Usuário inválido: use 3–41 caracteres (letras minúsculas, números, ponto, hífen ou sublinhado).');

            return self::INVALID;
        }

        return AuditContext::as(self::ACTOR, fn () => match ((string) $this->argument('action')) {
            'set-password' => $this->setPassword($username, $auth),
            'status' => $this->status($username),
            'unlock' => $this->unlock($username),
            'disable' => $this->disable($username, $auth),
            'revoke-sessions' => $this->revokeSessions($username, $auth),
            default => $this->invalidAction(),
        });
    }

    private function setPassword(string $username, SuperAdminAuth $auth): int
    {
        $existing = User::where('username', $username)->first();
        if ($existing && $existing->auth_source !== User::SOURCE_SUPERADMIN) {
            $this->error("O usuário \"$username\" já existe e não é um SuperAdmin (conta de outra origem). Escolha outro --username.");

            return self::FAILURE;
        }
        $generated = (bool) $this->option('generate');
        $password = $this->readPassword($generated);
        if ($password === null) {
            return self::FAILURE;
        }
        if ($violations = PasswordPolicy::violations($password, $username)) {
            $this->error('Senha recusada pela política:');
            foreach ($violations as $v) {
                $this->line("  - $v");
            }

            return self::FAILURE;
        }

        $revoked = DB::transaction(function () use ($existing, $username, $password, $auth, $generated) {
            $user = $existing ?? new User(['username' => $username]);
            $user->forceFill([
                'name' => (string) $this->option('name') ?: 'SuperAdmin',
                'area' => 'Acesso de emergência',
                'roles' => array_values((array) config('bpm.auth.superadmin.roles')),
                'groups' => [],
                'is_active' => true,
                'disabled_reason' => null,
                'disabled_at' => null,
                'auth_source' => User::SOURCE_SUPERADMIN,
            ])->save();
            LocalCredential::updateOrCreate(['user_id' => $user->id], [
                'password_hash' => PasswordPolicy::hash($password),
                'password_changed_at' => now(),
                'failed_attempts' => 0,
                'locked_until' => null,
            ]);
            $revoked = $auth->revokeAll($user, 'password_changed');
            AuditLogger::record('auth.superadmin_password_set', 'user', $user->id, ['username' => $username, 'created' => $existing === null, 'generated' => $generated, 'sessionsRevoked' => $revoked], context: ['object_name' => $user->name]);

            return $revoked;
        });

        $this->info(($existing ? 'Senha do SuperAdmin atualizada' : 'SuperAdmin criado')." (usuário: $username).".($revoked ? " Sessões revogadas: $revoked." : ''));
        if ($generated) {
            $this->newLine();
            $this->warn('Senha gerada (exibida somente agora — guarde no cofre de senhas da organização):');
            $this->line('  '.$password);
            $this->newLine();
        }
        if (! config('bpm.auth.superadmin.enabled')) {
            $this->warn('O login SuperAdmin está DESABILITADO neste ambiente. Para usar: BPM_SUPERADMIN_LOGIN_ENABLED=true (e BPM_SUPERADMIN_ALLOWED_IPS em produção).');
        }

        return self::SUCCESS;
    }

    private function status(string $username): int
    {
        $user = $this->find($username);
        $this->line('Login SuperAdmin habilitado: '.(config('bpm.auth.superadmin.enabled') ? 'sim' : 'não'));
        $ips = (array) config('bpm.auth.superadmin.allowed_ips');
        $this->line('IPs permitidos: '.($ips ? implode(', ', $ips) : 'qualquer (somente fora de produção)'));
        if (! $user) {
            $this->warn("SuperAdmin \"$username\" não existe. Crie com: php artisan bpm:superadmin set-password");

            return self::SUCCESS;
        }
        $c = LocalCredential::where('user_id', $user->id)->first();
        $this->table(['Campo', 'Valor'], [
            ['Usuário', $username],
            ['Ativo', $user->is_active === false ? 'não ('.($user->disabled_reason ?? '—').')' : 'sim'],
            ['Senha definida em', $c?->password_changed_at?->toDateTimeString() ?? '—'],
            ['Bloqueado até', $c?->isLocked() ? $c->locked_until?->toDateTimeString() : 'não'],
            ['Falhas consecutivas', (string) ($c->failed_attempts ?? 0)],
            ['Último acesso', $c?->last_login_at?->toDateTimeString() ?? '—'],
            ['Sessões ativas', (string) AuthSession::active()->where('user_id', $user->id)->count()],
        ]);

        return self::SUCCESS;
    }

    private function unlock(string $username): int
    {
        $user = $this->find($username);
        $c = $user ? LocalCredential::where('user_id', $user->id)->first() : null;
        if (! $user || ! $c) {
            $this->error("SuperAdmin \"$username\" não existe.");

            return self::FAILURE;
        }
        $c->forceFill(['failed_attempts' => 0, 'locked_until' => null])->save();
        AuditLogger::record('auth.superadmin_unlocked', 'user', $user->id, ['username' => $username], context: ['object_name' => $user->name]);
        $this->info('SuperAdmin desbloqueado.');

        return self::SUCCESS;
    }

    private function disable(string $username, SuperAdminAuth $auth): int
    {
        $user = $this->find($username);
        if (! $user) {
            $this->error("SuperAdmin \"$username\" não existe.");

            return self::FAILURE;
        }
        $revoked = DB::transaction(function () use ($user, $auth, $username) {
            $user->forceFill(['is_active' => false, 'disabled_reason' => 'superadmin_disabled', 'disabled_at' => now()])->save();
            $revoked = $auth->revokeAll($user, 'disabled');
            AuditLogger::record('auth.superadmin_disabled', 'user', $user->id, ['username' => $username, 'sessionsRevoked' => $revoked], context: ['object_name' => $user->name]);

            return $revoked;
        });
        $this->info("SuperAdmin desativado. Sessões revogadas: $revoked. Para reativar, defina uma nova senha (set-password).");

        return self::SUCCESS;
    }

    private function revokeSessions(string $username, SuperAdminAuth $auth): int
    {
        $user = $this->find($username);
        if (! $user) {
            $this->error("SuperAdmin \"$username\" não existe.");

            return self::FAILURE;
        }
        $revoked = $auth->revokeAll($user, 'revoked_by_operator');
        AuditLogger::record('auth.sessions_revoked', 'user', $user->id, ['method' => AuthMethods::SUPERADMIN, 'count' => $revoked], context: ['object_name' => $user->name]);
        $this->info("Sessões revogadas: $revoked.");

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Ação inválida. Use: set-password | status | unlock | disable | revoke-sessions');

        return self::INVALID;
    }

    private function find(string $username): ?User
    {
        return User::where('username', $username)->where('auth_source', User::SOURCE_SUPERADMIN)->first();
    }

    private function readPassword(bool $generate): ?string
    {
        if ($generate) {
            return PasswordPolicy::generate();
        }
        if ($file = $this->option('password-file')) {
            if (! is_file($file) || ! is_readable($file)) {
                $this->error('Arquivo de senha inexistente ou sem permissão de leitura.');

                return null;
            }

            return rtrim((string) file_get_contents($file), "\r\n");
        }
        if ($this->option('stdin')) {
            return rtrim((string) stream_get_contents(STDIN), "\r\n");
        }
        if (! $this->input->isInteractive()) {
            $this->error('Sem terminal interativo: use --stdin, --password-file ou --generate.');

            return null;
        }
        $first = (string) $this->secret('Nova senha do SuperAdmin (mín. '.config('bpm.auth.superadmin.password_min_length', 14).' caracteres)');
        $second = (string) $this->secret('Confirme a senha');
        if (! hash_equals($first, $second)) {
            $this->error('As senhas não conferem.');

            return null;
        }

        return $first;
    }
}
