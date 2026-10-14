<?php

namespace App\Http\Controllers\Api\V1;

use App\Auth\AuthMethods;
use App\Auth\SuperAdminAuth;
use App\Auth\LocalUserAuth;
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
        $data = $request->validate(['username' => ['required','string','max:200'], 'password' => ['required','string','max:256']]);
        return response()->json($this->local->attempt($data['username'], $data['password'], $request))->header('Cache-Control','no-store');
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
        $token = (string) $request->bearerToken();
        if ($method === AuthMethods::SUPERADMIN) {
            $this->superAdmin->revokeToken($token, 'logout');
        }
        AuditLogger::record('auth.logout', 'user', $user->id, ['method' => $method], null, $user, context: ['object_name' => $user->name]);

        return response()->noContent();
    }
}
