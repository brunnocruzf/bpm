<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\HoldDuringRestore;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyLocalCookieCsrf;
use App\Support\ApiProblem;
use App\Support\Correlation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$problem = function (int $status, string $title, ?string $detail, string $code, Request $request, array $extra = []) {
    return response()->json(array_filter([
        'type' => 'https://bpm.tmsa.local/problems/'.$code,
        'title' => $title,
        'status' => $status,
        'detail' => $detail,
        'code' => $code,
        'instance' => '/'.ltrim($request->path(), '/'),
        'correlationId' => Correlation::id(),
        ...$extra,
    ], fn ($v) => $v !== null), $status, ['Content-Type' => 'application/problem+json', 'X-Correlation-Id' => Correlation::id()]);
};

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(HoldDuringRestore::class);
        $middleware->prepend(AssignCorrelationId::class);
        $middleware->api(prepend: [VerifyLocalCookieCsrf::class], append: [SecurityHeaders::class]);
        $middleware->trustProxies(at: '*');
        // API não redireciona para tela de login: responde 401 (problem+json), mesmo sem Accept JSON.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions) use ($problem): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        // Erros de negócio (4xx) não são falhas da aplicação.
        $exceptions->dontReport([ApiProblem::class]);

        // RFC 7807 — problem details, sem stack trace nem dados sensíveis.
        $exceptions->render(function (ApiProblem $e, Request $request) use ($problem) {
            return $problem($e->status, $e->title, $e->detail, $e->problemCode, $request, array_filter(['errors' => $e->errors ?: null, ...$e->extra], fn ($v) => $v !== null));
        });
        $exceptions->render(function (ValidationException $e, Request $request) use ($problem) {
            return $problem(422, 'Dados inválidos', 'Verifique os campos destacados.', 'validation_failed', $request, ['errors' => $e->errors()]);
        });
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($problem) {
            return $problem(401, 'Não autenticado', 'Faça login novamente.', 'unauthenticated', $request);
        });
        $exceptions->render(function (AuthorizationException $e, Request $request) use ($problem) {
            return $problem(403, 'Acesso negado', 'Você não tem permissão para esta ação.', 'forbidden', $request);
        });
        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($problem) {
            return $problem(404, 'Não encontrado', 'Recurso não encontrado ou sem permissão de acesso.', 'not_found', $request);
        });
        $exceptions->render(function (InvalidSignatureException $e, Request $request) use ($problem) {
            return $problem(403, 'Link expirado', 'O link de download expirou. Gere um novo.', 'invalid_signature', $request);
        });
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($problem) {
            return $problem(429, 'Muitas requisições', 'Aguarde alguns instantes e tente novamente.', 'rate_limited', $request);
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($problem) {
            if (! $request->is('api/*')) {
                return null;
            }
            $status = $e->getStatusCode();

            return $problem($status, $status === 404 ? 'Não encontrado' : 'Erro', $status === 404 ? 'Recurso não encontrado.' : null, $status === 404 ? 'not_found' : 'http_error', $request);
        });
        $exceptions->render(function (Throwable $e, Request $request) use ($problem) {
            if (! $request->is('api/*') || config('app.debug')) {
                return null;
            }

            return $problem(500, 'Erro interno', 'Ocorreu um erro inesperado. Informe o código de correlação ao suporte.', 'internal_error', $request);
        });
    })->create();
