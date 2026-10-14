<?php

namespace Tests\Feature;

use App\Integrations\Ax\AxGateway;
use App\Integrations\IntegrationUnavailable;
use App\Models\AuditEvent;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEndpoint;
use App\Models\IntegrationExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Catálogo de integrações REST (ADR-0016): conexões, importação OpenAPI, endpoints, vínculos, execuções. */
class IntegrationCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://apidax.test';

    private array $doc = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        config(['bpm.integrations.allowed_hosts' => ['apidax.test']]);
        $this->doc = json_decode((string) file_get_contents(base_path('tests/Fixtures/openapi-ax-sample.json')), true);
    }

    private function connection(array $extra = []): array
    {
        return $this->as('admin')->postJson('/api/v1/admin/integrations/connections', [
            'key' => 'apidax', 'name' => 'API Dynamics AX 2012', 'system' => 'AX2012', 'baseUrl' => self::BASE, 'environment' => 'homologacao',
            'authType' => 'login', 'authConfig' => ['loginPath' => '/v1/User/login', 'userName' => 'svc_bpm', 'tokenTtlMinutes' => 30],
            'secret' => 'Senha-Do-Servico-1', 'openapiUrl' => self::BASE.'/swagger/v1/swagger.json', ...$extra,
        ])->assertCreated()->json();
    }

    private function importAll(string $connId, ?array $only = null): array
    {
        $preview = $this->as('admin')->postJson("/api/v1/admin/integrations/connections/$connId/openapi/preview")->assertOk()->json();
        $ops = array_map(fn ($o) => $o['method'].' '.$o['path'], $preview['operations']);

        return [$preview, $this->as('admin')->postJson("/api/v1/admin/integrations/connections/$connId/openapi/apply", [
            'previewId' => $preview['previewId'], 'operations' => $only ?? $ops,
        ])->assertOk()->json()];
    }

    private function fakeApi(array $routes = []): void
    {
        Http::fake([
            self::BASE.'/swagger/v1/swagger.json' => fn () => Http::response($this->doc),
            self::BASE.'/v1/User/login' => Http::response(['token' => 'tok-servico-123']),
            ...$routes,
        ]);
    }

    public function test_openapi_preview_apply_and_contract_drift(): void
    {
        $this->fakeApi();
        $conn = $this->connection();
        $this->assertTrue($conn['credential']['configured']);
        $this->assertStringNotContainsString('Senha-Do-Servico-1', (string) json_encode($this->as('admin')->getJson('/api/v1/admin/integrations/connections')->json()));

        [$preview, $applied] = $this->importAll($conn['id'], ['GET /v1/Fornecedores/{CPF_CNPJ}', 'GET /v1/Itens/Senf/TodosItens/{filter}/{site}', 'POST /v1/Financeiro/CriarOrdemTransferencia', 'GET /v1/Geral/Endereco/{cep}']);
        $this->assertSame(6, $preview['meta']['operations']);
        $this->assertSame(3, $preview['meta']['writes']);
        $this->assertSame(['new'], array_values(array_unique(array_column($preview['operations'], 'status'))));
        $this->assertTrue(collect($preview['warnings'])->contains(fn ($w) => str_contains($w, 'não documentam a resposta')));
        $this->assertTrue(collect($preview['warnings'])->contains(fn ($w) => str_contains($w, 'query string')));
        $this->assertSame(['created' => 4, 'updated' => 0, 'unchanged' => 0, 'removed' => 0], $applied);

        $endpoints = IntegrationEndpoint::all()->keyBy('key');
        $this->assertFalse($endpoints['ListaFornCNPJ']->enabled, 'importado desabilitado');
        $this->assertSame([['name' => 'CPF_CNPJ', 'in' => 'path', 'type' => 'string', 'required' => true, 'description' => null, 'mode' => 'input', 'constant' => null]], $endpoints['ListaFornCNPJ']->params);
        $this->assertContains('lines[].itemId', array_column($endpoints['CriarOT']->body_fields, 'path'));
        $this->assertTrue($endpoints['CriarOT']->isWrite());
        $this->assertSame(['logradouro', 'cidade', 'uf'], array_column($endpoints['GetEnderecoAsync']->response_fields, 'path'), 'resposta documentada é importada');
        $this->assertTrue(AuditEvent::where('action', 'integration.openapi_imported')->exists());

        // contrato mudou: novo parâmetro de query em Fornecedores; renomeio feito pelo admin é preservado
        $endpoints['ListaFornCNPJ']->update(['name' => 'Fornecedor por CNPJ (renomeado)']);
        $this->doc['paths']['/v1/Fornecedores/{CPF_CNPJ}']['get']['parameters'][] = ['name' => 'site', 'in' => 'query', 'schema' => ['type' => 'string']];
        $preview2 = $this->as('admin')->postJson("/api/v1/admin/integrations/connections/{$conn['id']}/openapi/preview")->assertOk()->json();
        $status = collect($preview2['operations'])->mapWithKeys(fn ($o) => [$o['method'].' '.$o['path'] => $o['status']]);
        $this->assertSame('changed', $status['GET /v1/Fornecedores/{CPF_CNPJ}']);
        $this->assertSame('unchanged', $status['GET /v1/Geral/Endereco/{cep}']);
        $this->assertSame('new', $status['POST /Access']);

        // verificação diária só MARCA (não aplica)
        $this->as('admin')->putJson("/api/v1/admin/integrations/connections/{$conn['id']}", ['enabled' => true])->assertOk();
        Artisan::call('bpm:openapi-check');
        $this->assertSame('changed', $endpoints['ListaFornCNPJ']->fresh()->contract_status);
        $this->assertCount(1, $endpoints['ListaFornCNPJ']->fresh()->params);

        $this->as('admin')->postJson("/api/v1/admin/integrations/connections/{$conn['id']}/openapi/apply", ['previewId' => $preview2['previewId'], 'operations' => ['GET /v1/Fornecedores/{CPF_CNPJ}']])
            ->assertOk()->assertJsonPath('updated', 1);
        $fresh = $endpoints['ListaFornCNPJ']->fresh();
        $this->assertSame('ok', $fresh->contract_status);
        $this->assertSame('Fornecedor por CNPJ (renomeado)', $fresh->name);
        $this->assertCount(2, $fresh->params);
    }

    public function test_endpoint_wizard_rules_and_response_inference_never_store_real_values(): void
    {
        $this->fakeApi();
        $conn = $this->connection();
        $this->importAll($conn['id'], ['GET /v1/Fornecedores/{CPF_CNPJ}']);
        $e = IntegrationEndpoint::where('key', 'ListaFornCNPJ')->firstOrFail();
        $url = "/api/v1/admin/integrations/endpoints/{$e->id}";

        // credencial como constante (como no print do SoftExpert) é recusada
        $this->as('admin')->putJson($url, ['params' => [
            ['name' => 'CPF_CNPJ', 'in' => 'path', 'mode' => 'input'],
            ['name' => 'Authorization', 'in' => 'query', 'mode' => 'constant', 'constant' => 'Bearer 123|abc'],
        ]])->assertStatus(422)->assertJsonValidationErrors(['params.1'], 'errors');
        $this->as('admin')->putJson($url, ['params' => [['name' => 'outro', 'in' => 'query']]])->assertStatus(422)->assertJsonValidationErrors(['params'], 'errors');

        // publicar consulta sem campos de saída (Swagger do AX não documenta a resposta): mensagem orienta a etapa de saída
        $noFields = $this->as('admin')->putJson($url, ['lookup' => ['expose' => true, 'inputParam' => 'CPF_CNPJ', 'fields' => []]])->assertStatus(422)->json('errors');
        $this->assertStringContainsString('ainda não tem campos de saída', $noFields['lookup.fields'][0]);

        // exemplo colado SEM clicar em "Inferir" (tela manda lista vazia) + publicação no mesmo salvamento: o servidor infere
        $this->as('admin')->putJson($url, ['sample' => json_encode([['codigo' => 'C-1', 'nome' => 'Cliente Teste']]), 'responseFields' => [],
            'lookup' => ['expose' => true, 'inputParam' => 'CPF_CNPJ', 'fields' => ['codigo', 'nome']]])->assertOk()
            ->assertJsonPath('lookup.fields', ['codigo', 'nome'])->assertJsonPath('responseFields.1.path', 'nome');
        $this->as('admin')->putJson($url, ['lookup' => ['expose' => false], 'responseFields' => []])->assertOk();

        // saída inferida de um exemplo colado: só tipos/formatos, sensíveis marcados
        $sample = json_encode([['codigo' => 'F-0001', 'nome' => 'FORNECEDOR REAL LTDA', 'cnpj' => '12345678000199', 'contato' => ['email' => 'pessoa@empresa.com', 'fone' => '11999990000'], 'dataCadastro' => '07/04/2025']]);
        $res = $this->as('admin')->putJson($url, ['sample' => $sample])->assertOk()->json();
        $fields = collect($res['responseFields'])->keyBy('path');
        $this->assertTrue($res['responseIsList']);
        $this->assertTrue($fields['cnpj']['sensitive']);
        $this->assertTrue($fields['contato.email']['sensitive']);
        $this->assertFalse($fields['nome']['sensitive']);
        $this->assertSame('date', $fields['dataCadastro']['type']);
        $stored = json_encode(IntegrationEndpoint::find($e->id)?->toArray());
        foreach (['FORNECEDOR REAL', '12345678000199', 'pessoa@empresa.com', '11999990000'] as $real) {
            $this->assertStringNotContainsString($real, (string) $stored);
        }

        // consulta nos formulários: sensível exige confirmação explícita
        $this->as('admin')->putJson($url, ['lookup' => ['expose' => true, 'inputParam' => 'CPF_CNPJ', 'fields' => ['nome', 'cnpj']]])
            ->assertStatus(422)->assertJsonValidationErrors(['lookup.fields'], 'errors');
        $this->as('admin')->putJson($url, ['enabled' => true, 'lookup' => ['expose' => true, 'label' => 'Fornecedor por CNPJ', 'inputParam' => 'CPF_CNPJ', 'fields' => ['codigo', 'nome']]])->assertOk();
        $this->assertContains('api:ListaFornCNPJ', collect($this->as('admin')->getJson('/api/v1/meta')->json('lookupSources'))->pluck('key')->all());

        $infer = $this->as('admin')->postJson('/api/v1/admin/integrations/endpoints/infer', ['sample' => '{"data":{"itens":[{"id":1}]}}', 'resultPath' => 'data.itens'])->assertOk();
        $this->assertTrue($infer->json('inferred.isList'));
    }

    public function test_published_lookup_and_bound_operation_call_the_api_with_cached_login_token(): void
    {
        $this->fakeApi([self::BASE.'/v1/Fornecedores/*' => Http::response([['codigo' => 'F-77', 'nome' => 'Fornecedor Teste', 'cnpj' => '11222333000181', 'contato' => ['nome' => 'Contato Teste', 'email' => 'contato@teste.example', 'fone' => '1133334444']]])]);
        $conn = $this->connection(['enabled' => true]);
        $this->importAll($conn['id'], ['GET /v1/Fornecedores/{CPF_CNPJ}']);
        $e = IntegrationEndpoint::where('key', 'ListaFornCNPJ')->firstOrFail();
        $this->as('admin')->putJson("/api/v1/admin/integrations/endpoints/{$e->id}", ['enabled' => true, 'responseFields' => [
            ['path' => 'codigo', 'label' => 'Código'], ['path' => 'nome', 'label' => 'Nome'], ['path' => 'contato.nome', 'label' => 'Contato'],
            ['path' => 'contato.email', 'label' => 'E-mail', 'sensitive' => true], ['path' => 'contato.fone', 'label' => 'Telefone', 'sensitive' => true],
        ], 'responseIsList' => true, 'lookup' => ['expose' => true, 'inputParam' => 'CPF_CNPJ', 'fields' => ['codigo', 'nome']]])->assertOk();

        $this->as('cq')->getJson('/api/v1/lookups/api:ListaFornCNPJ?value=11222333000181')->assertOk()
            ->assertJsonPath('found', true)->assertJsonPath('data.nome', 'Fornecedor Teste')->assertJsonPath('transport', 'REST:apidax');
        $this->as('cq')->getJson('/api/v1/lookups/api:ListaFornCNPJ?value=11222333000181')->assertOk();
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/v1/User/login')), 'token em cache: um único login');
        $this->assertCount(2, Http::recorded(fn (Request $r) => str_contains($r->url(), '/v1/Fornecedores/')));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v1/Fornecedores/11222333000181') && $r->header('Authorization')[0] === 'Bearer tok-servico-123');

        // vínculo: a consulta fixa "supplier" do PO 159 passa a usar a API real
        $this->as('admin')->putJson('/api/v1/admin/integrations/bindings/supplier.lookup', [
            'endpointId' => $e->id, 'enabled' => true, 'inputMap' => ['CPF_CNPJ' => 'input:value'],
            'outputMap' => ['supplierName' => 'nome', 'contactName' => 'contato.nome', 'phone' => 'contato.fone', 'email' => 'contato.email'],
        ])->assertOk();
        $this->assertSame('REST:apidax', app(AxGateway::class)->transportName('supplier.lookup'));
        $this->as('cq')->getJson('/api/v1/lookups/supplier?value=11222333000181')->assertOk()
            ->assertJsonPath('data.supplierName', 'Fornecedor Teste')->assertJsonPath('data.email', 'contato@teste.example');

        // log mascarado: sem CNPJ, e-mail ou telefone reais
        $log = json_encode(IntegrationExecution::where('endpoint_key', 'ListaFornCNPJ')->get()->toArray());
        foreach (['11222333000181', 'contato@teste.example', '1133334444'] as $pii) {
            $this->assertStringNotContainsString($pii, (string) $log);
        }
        $this->assertSame(200, IntegrationExecution::where('operation', 'supplier.lookup')->value('http_status'));

        // vínculo ativo com endpoint desligado: falha (nunca volta para o MOCK em silêncio)
        $e->refresh()->update(['enabled' => false]);
        $this->as('cq')->getJson('/api/v1/lookups/supplier?value=11222333000181')->assertStatus(503);
    }

    public function test_writes_need_approval_are_idempotent_and_uncertain_results_require_reconciliation(): void
    {
        $calls = 0;
        $this->fakeApi([self::BASE.'/v1/Financeiro/CriarOrdemTransferencia' => function () use (&$calls) {
            $calls++;
            if ($calls >= 2) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['transferId' => 'OT-000123']);
        }]);
        $conn = $this->connection(['enabled' => true]);
        $this->importAll($conn['id'], ['POST /v1/Financeiro/CriarOrdemTransferencia']);
        $e = IntegrationEndpoint::where('key', 'CriarOT')->firstOrFail();
        $this->as('admin')->putJson("/api/v1/admin/integrations/endpoints/{$e->id}", ['enabled' => true])->assertOk();
        $this->as('admin')->putJson('/api/v1/admin/integrations/bindings/transferAndBlock', [
            'endpointId' => $e->id, 'enabled' => true,
            'inputMap' => ['body:transferId' => 'input:numeroOcdev', 'body:lines[].itemId' => 'input:codigoItem', 'body:inventLocationIdTo' => 'const:BLOQ'],
            'outputMap' => ['numeroDiarioTransferencia' => 'transferId'],
        ])->assertOk();
        $ax = app(AxGateway::class);

        try {
            $ax->execute('transferAndBlock', ['numeroOcdev' => 'OCD-1', 'codigoItem' => 'IT-9'], 'key-1');
            $this->fail('escrita sem liberação');
        } catch (IntegrationUnavailable $ex) {
            $this->assertSame('write_not_allowed', $ex->reason);
        }
        $this->as('admin')->putJson("/api/v1/admin/integrations/connections/{$conn['id']}", ['allowWrites' => true])->assertStatus(422)->assertJsonValidationErrors(['writesApproval'], 'errors');
        $this->as('admin')->putJson("/api/v1/admin/integrations/connections/{$conn['id']}", ['allowWrites' => true, 'writesApproval' => 'CHG-2026-0042'])->assertOk();

        $out = $ax->execute('transferAndBlock', ['numeroOcdev' => 'OCD-1', 'codigoItem' => 'IT-9'], 'key-1');
        $this->assertSame(['numeroDiarioTransferencia' => 'OT-000123'], $out);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/CriarOrdemTransferencia') && ($r->data()['transferId'] ?? null) === 'OCD-1' && ($r->data()['lines'][0]['itemId'] ?? null) === 'IT-9' && ($r->data()['inventLocationIdTo'] ?? null) === 'BLOQ');
        $this->assertSame($out, $ax->execute('transferAndBlock', ['numeroOcdev' => 'OCD-1', 'codigoItem' => 'IT-9'], 'key-1'), 'mesma chave: não repete o POST');
        $this->assertSame(1, $calls);

        // tempo esgotado após o envio → resultado incerto → não repete sozinho
        try {
            $ax->execute('transferAndBlock', ['numeroOcdev' => 'OCD-2', 'codigoItem' => 'IT-9'], 'key-2');
            $this->fail('timeout');
        } catch (IntegrationUnavailable $ex) {
            $this->assertSame('timeout', $ex->reason);
        }
        $this->assertSame('unknown', IntegrationExecution::where('idempotency_key', 'key-2')->value('result'));
        try {
            $ax->execute('transferAndBlock', ['numeroOcdev' => 'OCD-2', 'codigoItem' => 'IT-9'], 'key-2');
            $this->fail('deveria exigir reconciliação');
        } catch (IntegrationUnavailable $ex) {
            $this->assertSame('reconciliation_required', $ex->reason);
        }
        $this->assertSame(2, $calls);

        // reconciliação: conferido no AX que a ordem FOI criada → próxima tentativa devolve o resultado informado, sem POST
        $unknown = IntegrationExecution::where('idempotency_key', 'key-2')->where('result', 'unknown')->firstOrFail();
        $this->as('cq')->postJson("/api/v1/admin/integrations/executions/{$unknown->id}/reconcile", ['outcome' => 'done'])->assertForbidden();
        $this->as('gestor')->postJson("/api/v1/admin/integrations/executions/{$unknown->id}/reconcile", ['outcome' => 'done', 'resultData' => ['numeroDiarioTransferencia' => 'OT-000124']])->assertOk();
        $this->assertSame(['numeroDiarioTransferencia' => 'OT-000124'], $ax->execute('transferAndBlock', ['numeroOcdev' => 'OCD-2', 'codigoItem' => 'IT-9'], 'key-2'));
        $this->assertSame(2, $calls);

        // teste de escrita: exige confirmação e nunca em produção
        $this->as('admin')->postJson("/api/v1/admin/integrations/endpoints/{$e->id}/test", ['body' => '{}'])->assertStatus(422);
        IntegrationConnection::whereKey($conn['id'])->update(['environment' => 'producao']);
        $this->as('admin')->postJson("/api/v1/admin/integrations/endpoints/{$e->id}/test", ['body' => '{}', 'confirmWrite' => true])->assertStatus(409);
    }

    public function test_authentication_methods_send_the_right_header_and_the_connection_test_verifies_for_real(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class); // muitas chamadas seguidas de propósito
        $seen = [];
        Http::fake(function (Request $r) use (&$seen) {
            $seen[] = $r;
            if (str_ends_with($r->url(), '/oauth2/token')) {
                return Http::response(['access_token' => 'oauth-token-ficticio', 'expires_in' => 3600]);
            }
            if (str_ends_with($r->url(), '/v1/Financeiro/Moeda')) {
                return in_array($r->header('Authorization')[0] ?? '', ['Bearer 42|token-de-teste-ficticio', 'Bearer oauth-token-ficticio'], true) || $r->hasHeader('X-Api-Token')
                    || str_starts_with($r->header('Authorization')[0] ?? '', 'Basic ')
                    ? Http::response([['moeda' => 'BRL']]) : Http::response(['message' => 'Unauthorized'], 401);
            }

            return Http::response([], 404);
        });
        $base = ['name' => 'API', 'system' => 'AX2012', 'baseUrl' => self::BASE, 'enabled' => true];

        // Bearer: o valor colado com ou sem "Bearer " vira exatamente "Authorization: Bearer <token>"
        $c = $this->as('admin')->postJson('/api/v1/admin/integrations/connections', [...$base, 'key' => 'bearer', 'authType' => 'bearer_token', 'secret' => 'Bearer 42|token-de-teste-ficticio'])->assertCreated()->json();
        $untested = $this->as('admin')->postJson("/api/v1/admin/integrations/connections/{$c['id']}/test")->assertOk()->json('lastTest');
        $this->assertTrue($untested['ok']);
        $this->assertFalse($untested['verified'], 'sem caminho de verificação: não verificado (nunca sucesso presumido)');
        $this->assertSame('not_verified', $untested['code']);
        $this->as('admin')->putJson("/api/v1/admin/integrations/connections/{$c['id']}", ['authConfig' => ['testPath' => '/v1/Financeiro/Moeda']])->assertOk();
        $this->as('admin')->postJson("/api/v1/admin/integrations/connections/{$c['id']}/test")->assertOk()
            ->assertJsonPath('lastTest.ok', true)->assertJsonPath('lastTest.verified', true);
        $this->assertSame('Bearer 42|token-de-teste-ficticio', end($seen)->header('Authorization')[0]);
        $this->as('admin')->putJson("/api/v1/admin/integrations/connections/{$c['id']}", ['secret' => '42|token-errado'])->assertOk();
        $this->as('admin')->postJson("/api/v1/admin/integrations/connections/{$c['id']}/test")->assertOk()
            ->assertJsonPath('lastTest.ok', false)->assertJsonPath('lastTest.code', 'credential_invalid')->assertJsonPath('lastTestExplain.title', 'Credencial recusada pela API');
        $this->assertSame('Bearer 42|token-errado', end($seen)->header('Authorization')[0], 'sem o prefixo, o servidor acrescenta "Bearer "');

        // chave de API em cabeçalho próprio, com prefixo opcional
        $k = $this->as('admin')->postJson('/api/v1/admin/integrations/connections', [...$base, 'key' => 'apikey', 'authType' => 'api_key_header', 'secret' => 'chave-ficticia',
            'authConfig' => ['headerName' => 'X-Api-Token', 'prefix' => 'Token', 'testPath' => '/v1/Financeiro/Moeda']])->assertCreated()->json();
        $this->as('admin')->postJson("/api/v1/admin/integrations/connections/{$k['id']}/test")->assertJsonPath('lastTest.verified', true);
        $this->assertSame('Token chave-ficticia', end($seen)->header('X-Api-Token')[0]);
        $this->assertFalse(end($seen)->hasHeader('Authorization'));

        // Basic
        $this->as('admin')->postJson('/api/v1/admin/integrations/connections', [...$base, 'key' => 'basic', 'authType' => 'basic', 'secret' => 'senha-ficticia'])
            ->assertStatus(422)->assertJsonValidationErrors(['authConfig.userName'], 'errors');
        $b = $this->as('admin')->postJson('/api/v1/admin/integrations/connections', [...$base, 'key' => 'basic', 'authType' => 'basic', 'secret' => 'senha-ficticia',
            'authConfig' => ['userName' => 'svc_bpm', 'testPath' => '/v1/Financeiro/Moeda']])->assertCreated()->json();
        $this->as('admin')->postJson("/api/v1/admin/integrations/connections/{$b['id']}/test")->assertJsonPath('lastTest.verified', true);
        $this->assertSame('Basic '.base64_encode('svc_bpm:senha-ficticia'), end($seen)->header('Authorization')[0]);

        // OAuth2 client credentials: secret no corpo (form), token em cache
        $this->as('admin')->postJson('/api/v1/admin/integrations/connections', [...$base, 'key' => 'oauth', 'authType' => 'oauth2_client_credentials', 'authConfig' => ['tokenUrl' => 'http://apidax.test/oauth2/token']])
            ->assertStatus(422)->assertJsonValidationErrors(['authConfig.tokenUrl', 'authConfig.clientId'], 'errors');
        $o = $this->as('admin')->postJson('/api/v1/admin/integrations/connections', [...$base, 'key' => 'oauth', 'authType' => 'oauth2_client_credentials', 'secret' => 'client-secret-ficticio',
            'authConfig' => ['tokenUrl' => self::BASE.'/oauth2/token', 'clientId' => 'bpm-client', 'scope' => 'api.read', 'testPath' => '/v1/Financeiro/Moeda']])->assertCreated()->json();
        $this->as('admin')->postJson("/api/v1/admin/integrations/connections/{$o['id']}/test")->assertJsonPath('lastTest.verified', true);
        $tokenCall = collect($seen)->first(fn (Request $r) => str_ends_with($r->url(), '/oauth2/token'));
        $this->assertSame(['grant_type' => 'client_credentials', 'client_id' => 'bpm-client', 'client_secret' => 'client-secret-ficticio', 'scope' => 'api.read'], $tokenCall->data());
        $this->assertStringNotContainsString('client-secret-ficticio', $tokenCall->url());
        $this->assertSame('Bearer oauth-token-ficticio', end($seen)->header('Authorization')[0]);

        // nenhum segredo volta na API
        $listing = (string) json_encode($this->as('admin')->getJson('/api/v1/admin/integrations/connections')->json());
        foreach (['token-de-teste-ficticio', 'token-errado', 'chave-ficticia', 'senha-ficticia', 'client-secret-ficticio', 'oauth-token-ficticio'] as $secret) {
            $this->assertStringNotContainsString($secret, $listing);
        }
    }

    public function test_target_policy_permissions_and_redirects(): void
    {
        config(['bpm.integrations.allowed_hosts' => []]);
        $this->as('admin')->postJson('/api/v1/admin/integrations/connections', ['key' => 'interna', 'name' => 'Interna', 'system' => 'Outro', 'baseUrl' => 'https://10.0.2.20'])->assertCreated();
        $c = IntegrationConnection::where('key', 'interna')->firstOrFail();
        $this->as('admin')->postJson("/api/v1/admin/integrations/connections/{$c->id}/test")->assertOk()
            ->assertJsonPath('lastTest.ok', false)->assertJsonPath('lastTest.code', 'target_blocked');
        $this->as('admin')->postJson("/api/v1/admin/integrations/connections/{$c->id}/openapi/preview", ['url' => 'http://169.254.169.254/latest'])->assertStatus(422)->assertJsonPath('code', 'target_blocked');
        $this->as('admin')->postJson('/api/v1/admin/integrations/connections', ['key' => 'com-senha', 'name' => 'X', 'system' => 'X', 'baseUrl' => 'https://user:pass@apidax.test'])->assertStatus(422);

        // redirecionamento não é seguido
        config(['bpm.integrations.allowed_hosts' => ['apidax.test']]);
        $this->fakeApi([self::BASE.'/v1/Geral/Endereco/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
        $conn = $this->connection(['enabled' => true]);
        $this->importAll($conn['id'], ['GET /v1/Geral/Endereco/{cep}']);
        $e = IntegrationEndpoint::where('key', 'GetEnderecoAsync')->firstOrFail();
        $this->as('admin')->postJson("/api/v1/admin/integrations/endpoints/{$e->id}/test", ['inputs' => ['cep' => '89000000']])->assertOk()
            ->assertJsonPath('ok', false)->assertJsonPath('code', 'redirect_blocked');

        // permissões: configuração só settings.manage; execuções também para auditor/gestor
        $this->as('gestor')->getJson('/api/v1/admin/integrations/connections')->assertForbidden();
        $this->as('auditor')->putJson("/api/v1/admin/integrations/endpoints/{$e->id}", ['enabled' => true])->assertForbidden();
        $this->as('auditor')->getJson('/api/v1/admin/integrations/executions')->assertOk()->assertJsonPath('data.0.endpoint', 'GetEnderecoAsync');
        $this->as('cq')->getJson('/api/v1/admin/integrations/executions')->assertForbidden();
    }
}
