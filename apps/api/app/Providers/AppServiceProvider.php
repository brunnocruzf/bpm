<?php

namespace App\Providers;

use App\Audit\AuditService;
use App\Auth\TokenUserResolver;
use App\Authorization\Permissions;
use App\Identity\EntraAppTokenProvider;
use App\Models\AuditEvent;
use App\Models\ReportJob;
use App\Policies\AuditEventPolicy;
use App\Policies\ReportJobPolicy;
use App\Storage\Azure\AzureBlobAdapter;
use App\Storage\Azure\AzureBlobClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditService::class);
        // tokens app-only ficam somente em memória do processo
        $this->app->singleton(EntraAppTokenProvider::class);
    }

    public function boot(): void
    {
        // Falha cedo se o stub de autenticação estiver ativo em produção.
        TokenUserResolver::assertSafeConfiguration();

        // Guard "bpm-token": Entra ID (JWT/JWKS) ou stub dev, conforme BPM_AUTH_DRIVER.
        Auth::viaRequest('bpm-token', fn (Request $request) => app(TokenUserResolver::class)($request));

        // Autorização no servidor: Gates por permissão + Policies por recurso (negar por padrão).
        Permissions::register();

        // Destino de anexos em Azure Blob Storage (Shared Key), construído em memória a partir do registro de destinos
        Storage::extend('bpm-azure-blob', function ($app, array $config) {
            $client = new AzureBlobClient((string) $config['account'], (string) $config['key'], (string) $config['endpoint'], (string) $config['container'],
                (string) config('bpm.storage.azure.api_version'), (int) config('bpm.storage.azure.timeout', 30), (int) config('bpm.storage.azure.connect_timeout', 5));
            $adapter = new AzureBlobAdapter($client, (string) ($config['prefix'] ?? ''));

            return new FilesystemAdapter(new Filesystem($adapter, $config), $adapter, $config);
        });
        Gate::policy(AuditEvent::class, AuditEventPolicy::class);
        Gate::policy(ReportJob::class, ReportJobPolicy::class);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }
}
