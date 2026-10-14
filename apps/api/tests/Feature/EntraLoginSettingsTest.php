<?php

namespace Tests\Feature;

use App\Auth\EntraAuthConfig;
use App\Models\AppSetting;
use App\Models\AuditEvent;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Configuração do login (SPA/MSAL + resource server): validação real por descoberta OIDC e teste de
 * token antes de ativar. Login da aplicação é independente da sincronização Graph (app-only).
 */
class EntraLoginSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '99999999-1111-4222-8333-444444444444';

    private string $key = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->key = (string) file_get_contents(base_path('tests/Fixtures/entra-test-private-key.pem'));
        $details = openssl_pkey_get_details(openssl_pkey_get_private($this->key));
        $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $tenant = self::TENANT;
        Http::fake([
            "login.microsoftonline.com/$tenant/v2.0/.well-known/openid-configuration" => Http::response([
                'issuer' => "https://login.microsoftonline.com/$tenant/v2.0",
                'jwks_uri' => "https://login.microsoftonline.com/$tenant/discovery/v2.0/keys",
                'authorization_endpoint' => "https://login.microsoftonline.com/$tenant/oauth2/v2.0/authorize",
                'code_challenge_methods_supported' => ['plain', 'S256'],
            ]),
            "login.microsoftonline.com/$tenant/discovery/v2.0/keys" => Http::response(['keys' => [['kty' => 'RSA', 'kid' => 'k1', 'use' => 'sig', 'alg' => 'RS256', 'n' => $b64($details['rsa']['n']), 'e' => $b64($details['rsa']['e'])]]]),
            'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_tenant'], 400),
        ]);
    }

    private function token(array $override = []): string
    {
        return JWT::encode([
            'iss' => 'https://login.microsoftonline.com/'.self::TENANT.'/v2.0', 'aud' => 'api://bpm-tmsa-api-hml', 'tid' => self::TENANT, 'oid' => 'oid-hml-1',
            'name' => 'Pessoa Homologação', 'scp' => 'access_as_user', 'iat' => time(), 'nbf' => time() - 5, 'exp' => time() + 600, ...$override,
        ], $this->key, 'RS256', 'k1');
    }

    private function configure(): void
    {
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/auth', [
            'tenantId' => self::TENANT, 'spaClientId' => '12345678-aaaa-4bbb-8ccc-1234567890ab',
            'redirectUris' => ['https://bpm-hml.tmsa.example', 'http://localhost:5173'], 'postLogoutRedirectUri' => 'https://bpm-hml.tmsa.example',
            'apiAudience' => 'api://bpm-tmsa-api-hml', 'apiScope' => 'access_as_user',
        ])->assertOk()->assertJsonPath('status', 'test_pending');
    }

    public function test_only_non_secret_parameters_with_safe_values_are_accepted(): void
    {
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/auth', ['tenantId' => 'common'])->assertStatus(422)->assertJsonValidationErrors('tenantId');
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/auth', ['redirectUris' => ['http://bpm.tmsa.example']])->assertStatus(422)->assertJsonValidationErrors('redirectUris');
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/auth', ['authorityHost' => 'evil.example'])->assertStatus(422);
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/auth', ['tenantId' => self::TENANT])->assertOk()->assertJsonPath('status', 'incomplete');
        $this->as('cq')->putJson('/api/v1/admin/settings/entra/auth', ['tenantId' => self::TENANT])->assertForbidden();
    }

    public function test_activation_requires_real_discovery_and_token_test(): void
    {
        $this->configure();
        $this->as('admin')->postJson('/api/v1/admin/settings/entra/auth/activate')->assertStatus(409)->assertJsonPath('code', 'validation_required');

        $this->as('admin')->postJson('/api/v1/admin/settings/entra/auth/validate')->assertOk()
            ->assertJsonPath('status', 'test_pending')->assertJsonPath('lastValidation.code', 'discovery_ok')->assertJsonPath('lastValidation.tenantGuid', self::TENANT);
        $this->as('admin')->postJson('/api/v1/admin/settings/entra/auth/activate')->assertStatus(409);

        // token com audience errada (ex.: ID token da SPA) é recusado
        $this->as('admin')->postJson('/api/v1/admin/settings/entra/auth/token-test', ['accessToken' => $this->token(['aud' => '12345678-aaaa-4bbb-8ccc-1234567890ab'])])->assertOk()
            ->assertJsonPath('status', 'error')->assertJsonPath('lastValidation.code', 'audience_mismatch');
        $this->as('admin')->postJson('/api/v1/admin/settings/entra/auth/validate')->assertOk();
        $this->as('admin')->postJson('/api/v1/admin/settings/entra/auth/token-test', ['accessToken' => $this->token(['exp' => time() - 3600])])->assertJsonPath('lastValidation.code', 'token_expired');
        $this->as('admin')->postJson('/api/v1/admin/settings/entra/auth/validate')->assertOk();
        $ok = $this->as('admin')->postJson('/api/v1/admin/settings/entra/auth/token-test', ['accessToken' => $token = $this->token()])->assertOk()
            ->assertJsonPath('status', 'configured')->assertJsonPath('lastValidation.code', 'token_ok');
        $this->assertStringNotContainsString($token, $ok->getContent(), 'token nunca é devolvido');
        $this->assertStringNotContainsString(substr($token, 0, 40), json_encode(AuditEvent::all()->toArray()).json_encode(AppSetting::all()->toArray()));

        $this->as('admin')->postJson('/api/v1/admin/settings/entra/auth/activate')->assertOk()->assertJsonPath('activatedAt', fn ($v) => $v !== null);
        $this->getJson('/api/v1/public/auth-config')->assertOk()->assertJsonPath('source', 'settings')
            ->assertJsonPath('entra.clientId', '12345678-aaaa-4bbb-8ccc-1234567890ab')->assertJsonPath('entra.scopes.0', 'api://bpm-tmsa-api-hml/access_as_user');

        // configuração efetiva do resource server passa a ser a ativada
        $this->assertSame(['api://bpm-tmsa-api-hml'], app(EntraAuthConfig::class)->effective()['audiences']);
        config(['bpm.auth.driver' => 'entra']);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$this->token())->getJson('/api/v1/me')->assertOk()->assertJsonPath('name', 'Pessoa Homologação');
        $this->assertNotNull(User::where('entra_oid', 'oid-hml-1')->first());

        // qualquer alteração exige nova validação/ativação
        config(['bpm.auth.driver' => 'dev']);
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/auth', ['apiScope' => 'outro_scope'])->assertOk()->assertJsonPath('activatedAt', null)->assertJsonPath('status', 'test_pending');
        $this->getJson('/api/v1/public/auth-config')->assertJsonPath('source', 'environment')->assertJsonPath('entra', null);
    }

    public function test_discovery_reports_unknown_tenant(): void
    {
        $this->as('admin')->putJson('/api/v1/admin/settings/entra/auth', [
            'tenantId' => '00000000-0000-4000-8000-00000000dead', 'spaClientId' => '12345678-aaaa-4bbb-8ccc-1234567890ab',
            'redirectUris' => ['https://bpm.tmsa.example'], 'apiAudience' => 'api://bpm', 'apiScope' => 'access_as_user',
        ])->assertOk();
        $this->as('admin')->postJson('/api/v1/admin/settings/entra/auth/validate')->assertOk()->assertJsonPath('status', 'error')->assertJsonPath('lastValidation.code', 'tenant_not_found');
    }
}
