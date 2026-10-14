<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\AuthMethods;
use App\Auth\LocalUserAuth;
use App\Auth\SuperAdminAuth;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Login SuperAdmin (usuário e senha locais, acesso de emergência) e saída auditada.
 * O login Entra ID acontece no navegador (MSAL) e o visitante usa usuários fictícios — ver ADR-0015.
 */
class AuthController extends ApiController
{
    public function __construct(private readonly SuperAdminAuth $superAdmin, private readonly LocalUserAuth $local) {}

    public function localLogin(Request $request): JsonResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:200'], 'password' => ['required', 'string', 'max:128']]);
        $session = $this->local->attempt($data['username'], $data['password'], $request);
        $token = $session['token'];
        unset($session['token']);
        $csrf = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $secure = app()->environment('production');

        return response()->json($session)->header('Cache-Control', 'no-store')
            ->cookie('bpm_local_session', $token, (int) config('bpm.auth.local.session_minutes', 240), '/api/v1', null, $secure, true, false, 'strict')
            ->cookie('bpm_local_csrf', $csrf, (int) config('bpm.auth.local.session_minutes', 240), '/', null, $secure, false, false, 'strict');
    }

    public function requestLocalPasswordReset(Request $request): JsonResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:200']]);
        $this->local->requestPasswordReset($data['username']);

        return response()->json(['message' => 'Se os dados corresponderem a uma conta local ativa, enviaremos as instruções para o e-mail cadastrado.'])->header('Cache-Control', 'no-store');
    }

    public function localCookieLogout(Request $request): Response
    {
        $user = $this->local->logoutCookie((string) $request->cookie('bpm_local_session', ''));
        if ($user) {
            AuditLogger::record('auth.logout', 'user', $user->id, ['method' => AuthMethods::LOCAL], null, $user, context: ['object_name' => $user->name]);
        }

        return response()->noContent()
            ->cookie('bpm_local_session', '', -1, '/api/v1', null, app()->environment('production'), true, false, 'strict')
            ->cookie('bpm_local_csrf', '', -1, '/', null, app()->environment('production'), false, false, 'strict');
    }

    public function activateLocalAccount(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128'], 'password' => ['required', 'string', 'max:128']]);
        $this->local->activate($data['token'], $data['password']);

        return response()->json(['message' => 'Conta ativada. Você já pode entrar.'])->header('Cache-Control', 'no-store');
    }

    public function resetLocalPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:128'], 'password' => ['required', 'string', 'max:128']]);
        $this->local->resetPassword($data['token'], $data['password']);

        return response()->json(['message' => 'Senha redefinida. Entre novamente com a nova senha.'])->header('Cache-Control', 'no-store');
    }

    public function superAdminLogin(Request $request): JsonResponse
    {
        $username = $request->input('username');
        $password = $request->input('password');
        if (! is_string($username) || ! is_string($password) || trim($username) === '' || $password === '') {
            throw ApiProblem::validation(array_filter([
                'username' => ! is_string($username) || trim($username) === '' ? ['Informe o usuário.'] : null,
                'password' => ! is_string($password) || $password === '' ? ['Informe a senha.'] : null,
            ]));
        }
        if (mb_strlen($username) > 120 || mb_strlen($password) > 256) {
            throw new ApiProblem(401, 'Não autenticado', 'Usuário ou senha inválidos, ou acesso temporariamente bloqueado.', 'invalid_credentials');
        }

        return response()->json($this->superAdmin->attempt($username, $password, $request))->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): Response
    {
        $user = $this->user();
        $method = (string) ($request->attributes->get('bpm.auth')['method'] ?? AuthMethods::ENTRA);
        $token = (string) ($request->bearerToken() ?: $request->cookie('bpm_local_session', ''));
        if ($method === AuthMethods::SUPERADMIN) {
            $this->superAdmin->revokeToken($token, 'logout');
        } elseif ($method === AuthMethods::LOCAL) {
            $this->local->revokeToken($token, 'logout');
        }
        AuditLogger::record('auth.logout', 'user', $user->id, ['method' => $method], null, $user, context: ['object_name' => $user->name]);

        $response = response()->noContent();
        if ($method === AuthMethods::LOCAL) {
            $response->withCookie(cookie('bpm_local_session', '', -1, '/api/v1', null, app()->environment('production'), true, false, 'strict'));
            $response->withCookie(cookie('bpm_local_csrf', '', -1, '/', null, app()->environment('production'), false, false, 'strict'));
        }

        return $response;
    }
}
