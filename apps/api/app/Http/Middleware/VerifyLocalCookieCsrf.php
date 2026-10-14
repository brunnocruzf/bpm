<?php

namespace App\Http\Middleware;

use App\Support\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyLocalCookieCsrf
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->cookie('bpm_local_session') && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $cookie = (string) $request->cookie('bpm_local_csrf', '');
            $header = (string) $request->header('X-BPM-CSRF', '');
            if ($cookie === '' || $header === '' || ! hash_equals($cookie, $header)) {
                throw new ApiProblem(419, 'Verificação de segurança falhou', 'Atualize a página e tente novamente.', 'csrf_mismatch');
            }
        }

        return $next($request);
    }
}
