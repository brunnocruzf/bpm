<?php

namespace Tests\Feature;

use App\Models\BpmGroup;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntraAuthTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-2222-3333-4444-555555555555';

    private const AUDIENCE = 'api://bpm-tmsa-api';

    private string $privateKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'bpm.auth.driver' => 'entra',
            'bpm.auth.entra.tenant_id' => self::TENANT,
            'bpm.auth.entra.allowed_tenants' => [self::TENANT],
            'bpm.auth.entra.audiences' => [self::AUDIENCE],
        ]);
        // Chave RSA exclusiva de teste (sem valor fora da suíte).
        $this->privateKey = (string) file_get_contents(base_path('tests/Fixtures/entra-test-private-key.pem'));
        $key = openssl_pkey_get_private($this->privateKey);
        $details = openssl_pkey_get_details($key);
        $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        Http::fake(['login.microsoftonline.com/*' => Http::response(['keys' => [[
            'kty' => 'RSA', 'kid' => 'kid-1', 'use' => 'sig', 'alg' => 'RS256',
            'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e']),
        ]]])]);
        BpmGroup::create(['key' => 'GEF', 'name' => 'GEF', 'entra_group_id' => 'aaaaaaaa-0000-0000-0000-000000000001']);
    }

    private function token(array $override = [], string $alg = 'RS256', ?string $key = null): string
    {
        $claims = array_merge([
            'iss' => 'https://login.microsoftonline.com/'.self::TENANT.'/v2.0',
            'aud' => self::AUDIENCE,
            'tid' => self::TENANT,
            'oid' => 'oid-123',
            'name' => 'Gabriel Entra',
            'preferred_username' => 'gabriel@tmsa.example',
            'department' => 'Gestão de Fornecedores',
            'scp' => 'access_as_user',
            'roles' => ['Bpm.TaskWorker', 'Outro.Papel'],
            'groups' => ['aaaaaaaa-0000-0000-0000-000000000001', 'grupo-desconhecido'],
            'iat' => time(), 'nbf' => time() - 5, 'exp' => time() + 3600,
        ], $override);

        return JWT::encode($claims, $key ?? $this->privateKey, $alg, 'kid-1');
    }

    private function me(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/v1/me');
    }

    public function test_valid_access_token_maps_identity_roles_and_groups_server_side(): void
    {
        $this->me($this->token())->assertOk()
            ->assertJsonPath('name', 'Gabriel Entra')
            ->assertJsonPath('area', 'Gestão de Fornecedores')
            ->assertJsonPath('roles', ['Bpm.TaskWorker', 'Bpm.Initiator'])
            ->assertJsonPath('groups.0.key', 'GEF');
        $user = User::where('entra_oid', 'oid-123')->first();
        $this->assertSame(self::TENANT, $user->entra_tid);
        $this->assertSame(['Bpm.Initiator', 'Bpm.TaskWorker'], $user->manual_roles, 'papéis padrão de conta nova');

        // papéis padrão só na criação: removidos pelo admin, não voltam no próximo login
        $user->forceFill(['manual_roles' => []])->save();
        $this->me($this->token(['roles' => []]))->assertOk()->assertJsonPath('roles', []);
    }

    public function test_rejects_tokens_with_wrong_audience_tenant_issuer_scope_or_expired(): void
    {
        $this->me($this->token(['aud' => 'api://outra-api']))->assertUnauthorized();
        $this->me($this->token(['tid' => '99999999-0000-0000-0000-000000000000']))->assertUnauthorized();
        $this->me($this->token(['iss' => 'https://evil.example/v2.0']))->assertUnauthorized();
        $this->me($this->token(['scp' => 'User.Read']))->assertUnauthorized();
        $this->me($this->token(['exp' => time() - 3600, 'nbf' => time() - 7200]))->assertUnauthorized();
        $this->me($this->token(['oid' => null]))->assertUnauthorized();
    }

    public function test_rejects_algorithm_confusion_and_dev_tokens_in_entra_mode(): void
    {
        $this->me($this->token([], 'HS256', 'qualquer-segredo-compartilhado-com-32-bytes!!'))->assertUnauthorized();
        $this->me('dev:admin')->assertUnauthorized();
    }
}
