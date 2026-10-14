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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        Gate::policy(AuditEvent::class, AuditEventPolicy::class);
        Gate::policy(ReportJob::class, ReportJobPolicy::class);

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }
}
