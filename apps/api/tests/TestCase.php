<?php

namespace Tests;

use App\Engine\EngineCommandProcessor;
use App\Models\EngineCommand;
use App\Models\NotificationMessage;
use App\Models\ReportJob;
use App\Models\User;
use App\Notifications\NotificationSender;
use App\Reports\ReportService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevUserSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /** Grupos/raias + usuários dev (sem fluxos seed). */
    protected function seedIdentity(): void
    {
        foreach (DatabaseSeeder::GROUPS as $key => [$name, $description]) {
            DB::table('bpm_groups')->insert(['id' => (string) Str::uuid(), 'key' => $key, 'name' => $name, 'description' => $description, 'email' => strtolower($key).'@bpm.tmsa.local', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->seed(DevUserSeeder::class);
    }

    protected function dev(string $username): User
    {
        return User::where('username', $username)->firstOrFail();
    }

    /** @return $this */
    protected function as(string $username): static
    {
        // o guard guarda o usuário resolvido; em testes a aplicação é reaproveitada entre requisições
        $this->app['auth']->forgetGuards();
        $this->flushHeaders(); // cabeçalhos (ex.: Idempotency-Key) persistem entre requisições do mesmo teste

        return $this->withHeader('Authorization', 'Bearer dev:'.$username);
    }

    /** Envia as notificações vencidas do outbox de e-mail (jobs são descartados pela fila "null"). */
    protected function drainNotifications(): int
    {
        NotificationMessage::whereIn('state', [NotificationMessage::QUEUED, NotificationMessage::RETRYING])->update(['available_at' => now()->subSecond()]);

        return app(NotificationSender::class)->dispatchDue(500);
    }

    /** Gera os relatórios pendentes (equivalente ao worker da fila "reports"). */
    protected function drainReports(): void
    {
        foreach (ReportJob::where('status', ReportJob::QUEUED)->pluck('id') as $id) {
            app(ReportService::class)->generate((string) $id);
        }
    }

    /** Processa o outbox até esvaziar (o driver de fila "null" descarta os jobs nos testes). */
    protected function drainOutbox(int $rounds = 20): void
    {
        $processor = app(EngineCommandProcessor::class);
        for ($i = 0; $i < $rounds; $i++) {
            EngineCommand::whereIn('state', [EngineCommand::PENDING, EngineCommand::RECONCILIATION_REQUIRED])->update(['available_at' => now()->subSecond()]);
            if ($processor->dispatchDue() === 0) {
                return;
            }
        }
    }
}
