<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Audit\AuditDiff;
use App\Authorization\Permissions;
use App\Forms\FieldCatalog;
use App\Http\Controllers\Api\V1\ApiController;
use App\Integrations\Ax\AxGateway;
use App\Integrations\BpmOperations;
use App\Integrations\IntegrationUnavailable;
use App\Integrations\Rest\JsonPaths;
use App\Integrations\Rest\OpenApiImporter;
use App\Integrations\Rest\RestCaller;
use App\Integrations\Rest\RestIntegration;
use App\Integrations\Rest\TargetPolicy;
use App\Models\FormSchemaVersion;
use App\Models\IntegrationBinding;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEndpoint;
use App\Models\IntegrationExecution;
use App\Models\ProcessStageVersion;
use App\Settings\SecretStore;
use App\Support\ApiProblem;
use App\Support\AuditLogger;
use App\Support\IntegrationErrors;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Catálogo de integrações REST (Administração → Integrações e entregas; ADR-0016), no estilo das
 * "fontes de dados REST" do SoftExpert: conexões (credencial somente escrita no cofre), importação
 * assistida do OpenAPI/Swagger, endpoints (serviço → entrada → saída → segurança), vínculos com as
 * operações do BPM e execuções. Configuração exige settings.manage; consulta de execuções também
 * processes.manage/audit.read. Toda chamada externa acontece no servidor.
 */
class IntegrationCatalogController extends ApiController
{
    private const AUTH_LIKE = '/^(authorization|cookie|proxy-authorization|x-api-key|api-key|apikey)$|pass|senha|secret|token/i';

    public function __construct(private readonly SecretStore $secrets) {}

    // ------------------------------------------------------------------ conexões

    public function connections(): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);

        return response()->json(['data' => IntegrationConnection::withCount(['endpoints', 'endpoints as enabled_endpoints_count' => fn ($q) => $q->where('enabled', true)])
            ->orderBy('name')->get()->map(fn (IntegrationConnection $c) => $this->presentConnection($c))->all(),
            'allowedHosts' => (array) config('bpm.integrations.allowed_hosts', [])]);
    }

    public function storeConnection(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $c = new IntegrationConnection(['enabled' => false, 'auth_type' => 'none', 'environment' => 'homologacao', 'timeout_seconds' => 15]);

        return response()->json($this->saveConnection($c, $request), 201);
    }

    public function updateConnection(Request $request, string $id): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);

        return response()->json($this->saveConnection($this->connection($id), $request));
    }

    public function testConnection(string $id, RestCaller $caller): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $c = $this->connection($id);
        $result = $caller->testConnection($c);
        $c->forceFill(['last_test' => [...$result, 'by' => $this->user()->name], 'last_tested_at' => now()])->save();
        AuditLogger::record('integration.connection_tested', 'integration_connection', $c->id, ['ok' => $result['ok'], 'code' => $result['code']], null, $this->user(),
            outcome: $result['ok'] ? 'success' : 'failure', context: ['object_name' => $c->name]);

        return response()->json($this->presentConnection($c->loadCount('endpoints')));
    }

    /** Prévia da importação: baixa o documento (URL) ou lê o arquivo enviado; nada é gravado. */
    public function openApiPreview(Request $request, string $id, OpenApiImporter $importer): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $c = $this->connection($id);
        try {
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                if (! $file || ! $file->isValid()) {
                    throw ApiProblem::validation(['file' => ['Falha no envio do arquivo.']]);
                }
                $parsed = $importer->parse((string) file_get_contents((string) $file->getRealPath()));
            } else {
                $url = trim((string) $request->input('url', $c->openapi_url));
                if ($url === '') {
                    throw ApiProblem::validation(['url' => ['Informe a URL do swagger.json ou envie o arquivo.']]);
                }
                $parsed = $importer->fetch($url);
                if ($url !== $c->openapi_url) {
                    $c->forceFill(['openapi_url' => mb_substr($url, 0, 300)])->save();
                }
            }
        } catch (IntegrationUnavailable $e) {
            throw new ApiProblem(422, 'Documento OpenAPI indisponível', $e->getMessage(), $e->reason, [], ['explain' => IntegrationErrors::explain($e->reason, 'rest')]);
        }
        $previewId = (string) Str::uuid();
        Cache::put('bpm:openapi-preview:'.$previewId, base64_encode((string) gzcompress((string) json_encode(['doc' => $parsed['doc'], 'hash' => $parsed['hash'], 'connection' => $c->id]), 6)), now()->addMinutes(30));

        return response()->json(['previewId' => $previewId, 'bytes' => $parsed['bytes'], 'sameAsImported' => $parsed['hash'] === $c->openapi_hash, ...$importer->preview($parsed['doc'], $c)]);
    }

    public function openApiApply(Request $request, string $id, OpenApiImporter $importer): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $c = $this->connection($id);
        $data = $request->validate(['previewId' => ['required', 'uuid'], 'operations' => ['array'], 'operations.*' => ['string', 'max:320'], 'keys' => ['array']]);
        $raw = Cache::get('bpm:openapi-preview:'.$data['previewId']);
        $cached = is_string($raw) ? json_decode((string) gzuncompress((string) base64_decode($raw)), true) : null;
        if (! is_array($cached) || ($cached['connection'] ?? null) !== $c->id) {
            throw ApiProblem::conflict('A prévia expirou (30 min). Gere a prévia novamente.', 'preview_expired');
        }
        $result = $importer->apply((array) $cached['doc'], (string) $cached['hash'], $c, array_values((array) ($data['operations'] ?? [])), array_map('strval', (array) ($data['keys'] ?? [])), $this->user());
        Cache::forget('bpm:openapi-preview:'.$data['previewId']);

        return response()->json($result);
    }

    // ------------------------------------------------------------------ endpoints

    public function endpoints(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $q = IntegrationEndpoint::with('connection')->orderBy('tag')->orderBy('key');
        if ($cid = $request->query('connection')) {
            $q->where('connection_id', (string) $cid);
        }
        if ($tag = $request->query('tag')) {
            $q->where('tag', (string) $tag);
        }
        match ((string) $request->query('status', '')) {
            'enabled' => $q->where('enabled', true),
            'disabled' => $q->where('enabled', false),
            'changed' => $q->whereIn('contract_status', ['changed', 'removed']),
            'lookup' => $q->where('lookup->expose', true),
            default => null,
        };
        match ((string) $request->query('kind', '')) {
            'read' => $q->where('method', 'GET'),
            'write' => $q->where('method', '!=', 'GET'),
            default => null,
        };
        if ($s = trim((string) $request->query('q', ''))) {
            $like = '%'.mb_strtolower($s).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(key) like ?', [$like])->orWhereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(path) like ?', [$like]));
        }
        $bound = IntegrationBinding::whereNotNull('endpoint_id')->pluck('operation', 'endpoint_id')->all();

        return response()->json(['data' => $q->limit(1000)->get()->map(fn (IntegrationEndpoint $e) => $this->presentEndpoint($e, false, $bound))->all(),
            'tags' => IntegrationEndpoint::whereNotNull('tag')->distinct()->orderBy('tag')->pluck('tag')]);
    }

    public function showEndpoint(string $id): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);

        return response()->json($this->presentEndpoint($this->endpoint($id), true));
    }

    public function storeEndpoint(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $c = $this->connection((string) $request->input('connectionId'));
        $e = new IntegrationEndpoint(['connection_id' => $c->id, 'source' => 'manual', 'enabled' => false, 'version' => 0]);

        return response()->json($this->saveEndpoint($e, $request), 201);
    }

    public function updateEndpoint(Request $request, string $id): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);

        return response()->json($this->saveEndpoint($this->endpoint($id), $request));
    }

    public function deleteEndpoint(string $id): Response
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $e = $this->endpoint($id);
        if (IntegrationBinding::where('endpoint_id', $e->id)->where('enabled', true)->exists()) {
            throw ApiProblem::conflict('Endpoint vinculado a uma operação ativa: desative o vínculo antes.', 'endpoint_in_use');
        }
        $forms = FormSchemaVersion::where('schema', 'like', '%"'.FieldCatalog::API_PREFIX.$e->key.'"%')->count();
        if ($forms > 0) {
            throw ApiProblem::conflict("Consulta usada em $forms formulário(s): retire a consulta dos formulários antes de remover.", 'endpoint_in_use');
        }
        AuditLogger::record('integration.endpoint_deleted', 'integration_endpoint', $e->id, ['key' => $e->key, 'method' => $e->method, 'path' => $e->path], null, $this->user(), context: ['object_name' => $e->name]);
        $e->delete();

        return response()->noContent();
    }

    /** Teste real do endpoint (servidor). Escrita só com confirmação, fora de produção e com escrita liberada. */
    public function testEndpoint(Request $request, string $id, RestIntegration $rest): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $e = $this->endpoint($id);
        $inputs = array_map(fn ($v) => is_scalar($v) ? (string) $v : null, (array) $request->input('inputs', []));
        $body = null;
        if ($e->isWrite()) {
            if (! $request->boolean('confirmWrite')) {
                throw ApiProblem::validation(['confirmWrite' => ['Confirme que este teste GRAVA no sistema externo.']]);
            }
            if ($e->connection->environment === 'producao') {
                throw ApiProblem::conflict('Teste de escrita não é permitido em conexão de produção.', 'write_test_forbidden');
            }
            $body = json_decode((string) $request->input('body', 'null'), true);
        }
        $result = $rest->test($e, $inputs, $body);
        if (! $result['ok']) {
            $result['explain'] = IntegrationErrors::explain($result['code'] ?? null, 'rest');
        }
        AuditLogger::record('integration.endpoint_tested', 'integration_endpoint', $e->id, ['key' => $e->key, 'ok' => $result['ok'], 'status' => $result['status'] ?? null, 'code' => $result['code'] ?? null], null, $this->user(),
            outcome: $result['ok'] ? 'success' : 'failure', context: ['object_name' => $e->name]);

        return response()->json($result);
    }

    /** Inferência dos campos de saída a partir de um exemplo colado (nada é chamado nem gravado). */
    public function inferResponse(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $raw = (string) $request->input('sample', '');
        if (strlen($raw) > 512 * 1024) {
            throw ApiProblem::validation(['sample' => ['Exemplo grande demais (máx. 512 KB).']]);
        }
        $json = json_decode($raw, true, 128);
        if ($json === null && trim($raw) !== 'null') {
            throw ApiProblem::validation(['sample' => ['JSON inválido.']]);
        }
        $data = $request->filled('resultPath') ? JsonPaths::get($json, (string) $request->input('resultPath')) : $json;

        return response()->json(['inferred' => JsonPaths::infer($data), 'sample' => JsonPaths::maskSample($data)]);
    }

    // ------------------------------------------------------------------ vínculos (operações do BPM → endpoints)

    /** Operações do BPM (fixas do PO 159 + mapeamentos personalizados) e seus vínculos. */
    public function bindings(): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $rows = IntegrationBinding::with('endpoint.connection')->get()->keyBy('operation');

        return response()->json(['data' => array_map(function (array $op) use ($rows) {
            $b = $rows->get($op['operation']);

            return [...$op, 'inputs' => (object) $op['inputs'], 'outputs' => (object) $op['outputs'],
                'configuredTransport' => $op['custom'] ? null : AxGateway::configuredTransport($op['operation']),
                'usage' => $op['custom'] ? $this->customUsage($op['operation'], $op['kind']) : null,
                'binding' => $b ? [
                    'endpointId' => $b->endpoint_id, 'endpointKey' => $b->endpoint?->key, 'endpointName' => $b->endpoint?->name, 'connection' => $b->endpoint?->connection->name,
                    'enabled' => $b->enabled, 'inputMap' => (object) ($b->input_map ?? []), 'outputMap' => (object) ($b->output_map ?? []), 'updatedAt' => $b->updated_at?->toIso8601String(),
                    'usable' => $b->endpoint && $b->endpoint->enabled && $b->endpoint->connection->enabled,
                ] : null];
        }, BpmOperations::all())]);
    }

    /** Novo mapeamento: operação do BPM criada pelo administrador (consulta nos formulários ou etapa automática do fluxo). */
    public function storeBinding(Request $request): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $data = $request->validate([
            'key' => ['required', 'string', 'regex:'.IntegrationBinding::CUSTOM_KEY],
            'kind' => ['required', Rule::in(IntegrationBinding::KINDS)],
        ], [
            'key.required' => 'Informe o identificador.',
            'key.regex' => 'Identificador inválido: comece com uma letra e use de 3 a 41 letras, números ou _ (sem espaços ou acentos).',
            'kind.required' => 'Escolha o tipo do mapeamento.',
            'kind.in' => 'Escolha o tipo do mapeamento.',
        ]);
        $operation = IntegrationBinding::CUSTOM_PREFIX.$data['key'];
        if (IntegrationBinding::whereRaw('lower(operation) = ?', [mb_strtolower($operation)])->exists()) {
            throw ApiProblem::validation(['key' => ['Já existe um mapeamento com este identificador.']]);
        }

        return $this->saveCustom($request, new IntegrationBinding(['operation' => $operation, 'custom' => true, 'kind' => $data['kind'], 'enabled' => false]), 201);
    }

    /** Exclui um mapeamento personalizado que não esteja em uso (formulários ou etapas de fluxo). */
    public function deleteBinding(string $operation): Response
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $b = IntegrationBinding::custom()->where('operation', $operation)->first()
            ?? throw ApiProblem::notFound('Somente mapeamentos criados em "Novo mapeamento" podem ser excluídos.');
        $usage = $this->customUsage($b->operation, (string) $b->kind);
        if ($usage['forms'] > 0) {
            throw ApiProblem::conflict("Consulta usada em {$usage['forms']} formulário(s): retire-a dos formulários antes de excluir.", 'binding_in_use');
        }
        if ($usage['stages'] > 0) {
            throw ApiProblem::conflict("Integração usada em {$usage['stages']} etapa(s) de fluxo: troque a integração dessas etapas antes de excluir.", 'binding_in_use');
        }
        AuditLogger::record('integration.binding_deleted', 'integration_binding', $b->id, ['operation' => $b->operation, 'kind' => $b->kind], null, $this->user(), context: ['object_name' => (string) $b->label]);
        $b->delete();

        return response()->noContent();
    }

    public function saveBinding(Request $request, string $operation): JsonResponse
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE);
        $op = BpmOperations::find($operation) ?? throw ApiProblem::notFound('Operação desconhecida.');
        if ($op['custom']) {
            return $this->saveCustom($request, IntegrationBinding::where('operation', $operation)->firstOrFail());
        }
        $data = $request->validate(['endpointId' => ['nullable', 'uuid'], 'enabled' => ['boolean'], 'inputMap' => ['array'], 'outputMap' => ['array']]);
        $endpoint = ! empty($data['endpointId']) ? $this->endpoint((string) $data['endpointId']) : null;
        $errors = [];
        if ($endpoint && $op['kind'] === 'lookup' && $endpoint->method !== 'GET') {
            $errors['endpointId'][] = 'Consultas precisam de endpoint de leitura (GET).';
        }
        [$inputMap, $inputErrors] = $this->sanitizeInputMap((array) ($data['inputMap'] ?? []), $endpoint, fn (string $field) => array_key_exists($field, $op['inputs']));
        if ($inputErrors) {
            $errors['inputMap'] = $inputErrors;
        }
        $outputMap = [];
        foreach ((array) ($data['outputMap'] ?? []) as $field => $path) {
            if ((string) $path === '') {
                continue;
            }
            if (! array_key_exists((string) $field, (array) $op['outputs']) || ! preg_match('/^[A-Za-z0-9_.\[\]\-$]{1,200}$/', (string) $path)) {
                $errors['outputMap'][] = "Saída inválida: $field ⇐ $path.";

                continue;
            }
            $outputMap[(string) $field] = (string) $path;
        }
        if (! empty($data['enabled']) && (! $endpoint || $outputMap === [] || $inputMap === [])) {
            $errors['enabled'][] = 'Para ativar, escolha o endpoint e mapeie entrada e saída.';
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }
        $b = IntegrationBinding::firstOrNew(['operation' => $operation]);
        $before = $b->exists ? $b->only(['endpoint_id', 'enabled', 'input_map', 'output_map']) : [];
        $b->fill(['endpoint_id' => $endpoint?->id, 'enabled' => (bool) ($data['enabled'] ?? false), 'input_map' => $inputMap, 'output_map' => $outputMap, 'updated_by' => $this->user()->id])->save();
        AuditLogger::record('integration.binding_saved', 'integration_binding', $b->id, ['operation' => $operation, 'endpoint' => $endpoint?->key, 'enabled' => $b->enabled], null, $this->user(),
            diff: AuditDiff::between($before, $b->only(['endpoint_id', 'enabled', 'input_map', 'output_map'])), context: ['object_name' => $op['label']]);

        return $this->bindings();
    }

    /**
     * Grava um mapeamento personalizado. A definição da operação sai do próprio de/para:
     *  - entrada: consulta recebe só o valor digitado ("input:value"); etapa automática lê os dados do
     *    processo citados ("input:<chave>");
     *  - saída: cada item {key, label, path} vira um dado devolvido ao BPM (campo do Consulta AX ou dado do processo).
     */
    private function saveCustom(Request $request, IntegrationBinding $b, int $status = 200): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:400'],
            'endpointId' => ['required', 'uuid'],
            'enabled' => ['boolean'],
            'inputMap' => ['array'],
            'outputs' => ['array', 'max:30'],
            'outputs.*.key' => ['required', 'string', 'regex:'.IntegrationBinding::FIELD_KEY],
            'outputs.*.label' => ['nullable', 'string', 'max:120'],
            'outputs.*.path' => ['required', 'string', 'regex:/^[A-Za-z0-9_.\[\]\-$]{1,200}$/'],
        ], [
            'label.required' => 'Informe o nome do mapeamento.',
            'endpointId.required' => 'Escolha o endpoint que o mapeamento vai chamar.',
            'outputs.max' => 'No máximo 30 dados de saída.',
            'outputs.*.key.required' => 'Informe a chave do dado no BPM.',
            'outputs.*.key.regex' => 'Chave inválida: comece com uma letra e use apenas letras, números ou _.',
            'outputs.*.path.required' => 'Informe o caminho na resposta.',
            'outputs.*.path.regex' => 'Caminho inválido (ex.: nome ou contato.email).',
        ]);
        $endpoint = $this->endpoint((string) $data['endpointId']);
        $lookup = $b->kind === 'lookup';
        $errors = [];
        if ($lookup && $endpoint->method !== 'GET') {
            $errors['endpointId'][] = 'Consultas nos formulários precisam de um endpoint de leitura (GET).';
        }
        [$inputMap, $inputErrors] = $this->sanitizeInputMap((array) ($data['inputMap'] ?? []), $endpoint,
            fn (string $field) => $lookup ? $field === 'value' : preg_match(IntegrationBinding::FIELD_KEY, $field) === 1);
        if ($inputErrors) {
            $errors['inputMap'] = $inputErrors;
        }
        $inputs = $lookup
            ? [['key' => 'value', 'label' => BpmOperations::LOOKUP_INPUT['value']]]
            : collect($inputMap)->filter(fn ($s) => str_starts_with($s, 'input:'))->map(fn ($s) => substr($s, 6))->unique()->values()->map(fn ($k) => ['key' => $k, 'label' => $k])->all();
        $outputs = [];
        $outputMap = [];
        foreach (array_values((array) ($data['outputs'] ?? [])) as $i => $row) {
            $key = (string) $row['key'];
            if (array_key_exists($key, $outputMap)) {
                $errors["outputs.$i.key"][] = "Chave repetida: $key.";

                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            $outputs[] = ['key' => $key, 'label' => $label !== '' ? $label : $key];
            $outputMap[$key] = (string) $row['path'];
        }
        $enabled = (bool) ($data['enabled'] ?? false);
        if ($enabled) {
            if ($outputMap === []) {
                $errors['outputs'][] = 'Para ativar, escolha pelo menos um dado de saída.';
            }
            if ($lookup && ! in_array('input:value', $inputMap, true)) {
                $errors['inputMap'][] = 'Para ativar, escolha qual parâmetro do endpoint recebe o valor digitado no formulário.';
            }
            if (! $lookup && $inputMap === []) {
                $errors['inputMap'][] = 'Para ativar, informe pelo menos uma entrada do endpoint.';
            }
            foreach ((array) ($endpoint->params ?? []) as $p) {
                if (! empty($p['required']) && ($p['mode'] ?? 'input') === 'input' && ! array_key_exists((string) $p['name'], $inputMap)) {
                    $errors['inputMap'][] = "Para ativar, informe o parâmetro obrigatório \"{$p['name']}\".";
                }
            }
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }
        $fields = ['endpoint_id', 'enabled', 'label', 'description', 'input_map', 'output_map', 'outputs'];
        $before = $b->exists ? $b->only($fields) : [];
        $b->fill(['endpoint_id' => $endpoint->id, 'enabled' => $enabled, 'label' => trim((string) $data['label']), 'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'inputs' => $inputs, 'outputs' => $outputs, 'input_map' => $inputMap, 'output_map' => $outputMap, 'updated_by' => $this->user()->id])->save();
        AuditLogger::record($status === 201 ? 'integration.binding_created' : 'integration.binding_saved', 'integration_binding', $b->id,
            ['operation' => $b->operation, 'kind' => $b->kind, 'endpoint' => $endpoint->key, 'enabled' => $b->enabled], null, $this->user(),
            diff: AuditDiff::between($before, $b->only($fields)), context: ['object_name' => (string) $b->label]);

        return $this->bindings()->setStatusCode($status);
    }

    /**
     * De/para da entrada: destino = parâmetro do endpoint ou "body:<caminho>"; origem = "input:<dado>" | "const:<valor>".
     * Constante nunca vai para destino com cara de credencial (fica na conexão).
     *
     * @param  array<mixed>  $map
     * @param  callable(string):bool  $validInput
     * @return array{0: array<string,string>, 1: list<string>}
     */
    private function sanitizeInputMap(array $map, ?IntegrationEndpoint $endpoint, callable $validInput): array
    {
        $paramNames = array_map(fn ($p) => (string) $p['name'], (array) ($endpoint ? $endpoint->params : []));
        $inputMap = [];
        $errors = [];
        foreach ($map as $target => $source) {
            $target = (string) $target;
            $source = (string) $source;
            if ($source === '') {
                continue;
            }
            $okTarget = in_array($target, $paramNames, true) || (str_starts_with($target, 'body:') && preg_match('/^body:[A-Za-z0-9_.\[\]\-]{1,200}$/', $target));
            $okSource = (str_starts_with($source, 'input:') && $validInput(substr($source, 6))) || (str_starts_with($source, 'const:') && mb_strlen($source) <= 206);
            if (! $okTarget || ! $okSource || (str_starts_with($source, 'const:') && preg_match(self::AUTH_LIKE, $target))) {
                $errors[] = "Entrada inválida: $target ⇐ $source.";

                continue;
            }
            $inputMap[$target] = $source;
        }

        return [$inputMap, $errors];
    }

    /**
     * Uso de um mapeamento personalizado: consulta → formulários que a citam; etapa → etapas de fluxo.
     *
     * @return array{forms:int, stages:int}
     */
    private function customUsage(string $operation, string $kind): array
    {
        if ($kind === 'lookup') {
            $like = '%"'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $operation).'"%';

            return ['forms' => FormSchemaVersion::where('schema', 'like', $like)->count(), 'stages' => 0];
        }

        return ['forms' => 0, 'stages' => ProcessStageVersion::where('stage_type', ProcessStageVersion::SERVICE_TASK)->where('config->operation', $operation)->count()];
    }

    // ------------------------------------------------------------------ execuções

    public function executions(Request $request): JsonResponse
    {
        if (! Permissions::allows($this->user(), Permissions::SETTINGS_MANAGE) && ! Permissions::allows($this->user(), Permissions::PROCESSES_MANAGE) && ! Permissions::allows($this->user(), Permissions::AUDIT_READ)) {
            throw ApiProblem::forbidden();
        }
        $q = IntegrationExecution::query()->orderByDesc('created_at');
        foreach (['system' => 'system', 'operation' => 'operation', 'endpoint' => 'endpoint_key', 'transport' => 'transport'] as $param => $col) {
            if ($v = $request->query($param)) {
                $q->where($col, (string) $v);
            }
        }
        if ($r = $request->query('result')) {
            $r === 'failures' ? $q->whereNotIn('result', ['success', 'not_found']) : $q->where('result', (string) $r);
        }
        if ($since = $request->query('since')) {
            $q->where('created_at', '>=', match ((string) $since) {
                '1h' => now()->subHour(), '24h' => now()->subDay(), '7d' => now()->subDays(7), default => now()->subDays(30)
            });
        }
        if ($s = trim((string) $request->query('q', ''))) {
            $like = '%'.mb_strtolower($s).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(operation) like ?', [$like])->orWhereRaw('lower(coalesce(endpoint_key, \'\')) like ?', [$like])->orWhereRaw('lower(coalesce(correlation_id, \'\')) like ?', [$like]));
        }
        $page = $q->simplePaginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (IntegrationExecution $x) => [
                'id' => $x->id, 'at' => $x->created_at?->toIso8601String(), 'system' => $x->system, 'operation' => $x->operation, 'transport' => $x->transport, 'endpoint' => $x->endpoint_key,
                'result' => $x->result, 'httpStatus' => $x->http_status, 'attempt' => $x->attempt, 'durationMs' => $x->duration_ms, 'correlationId' => $x->correlation_id,
                'processInstanceId' => $x->process_instance_id, 'idempotencyKey' => $x->idempotency_key ? substr($x->idempotency_key, 0, 60) : null,
                'request' => $x->request_masked, 'response' => $x->response_masked, 'explain' => in_array($x->result, ['success', 'not_found'], true) ? null : IntegrationErrors::explain($x->result, str_starts_with($x->transport, 'REST:') ? 'rest' : null),
            ])->all(),
            'next' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
            'stats' => IntegrationExecution::where('created_at', '>=', now()->subDay())->selectRaw('result, count(*) as total')->groupBy('result')->pluck('total', 'result'),
        ]);
    }

    /**
     * Reconciliação de escrita com resultado incerto: o operador confere no sistema de destino e marca
     * "foi feita" (informando as saídas, ex.: número da ordem) ou "não foi feita" (libera nova tentativa).
     */
    public function reconcileExecution(Request $request, string $id): JsonResponse
    {
        Gate::authorize(Permissions::PROCESSES_MANAGE);
        $x = IntegrationExecution::find($id) ?? throw ApiProblem::notFound();
        if ($x->result !== 'unknown') {
            throw ApiProblem::conflict('Somente execuções com resultado incerto podem ser reconciliadas.', 'not_reconcilable');
        }
        $data = $request->validate(['outcome' => ['required', 'in:done,not_done'], 'resultData' => ['array'], 'note' => ['nullable', 'string', 'max:300']]);
        $outputs = array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 200) : null, (array) ($data['resultData'] ?? []));
        $x->forceFill(['result' => $data['outcome'] === 'done' ? 'success' : 'reconciled_retry', 'result_data' => $data['outcome'] === 'done' ? $outputs : null,
            'response_masked' => [...(array) ($x->response_masked ?? []), 'reconciliation' => ['outcome' => $data['outcome'], 'by' => $this->user()->name, 'at' => now()->toIso8601String(), 'note' => $data['note'] ?? null]]])->save();
        AuditLogger::record('integration.execution_reconciled', 'integration_execution', $x->id, ['operation' => $x->operation, 'outcome' => $data['outcome'], 'outputs' => array_keys($outputs)], $x->process_instance_id, $this->user(), context: ['object_name' => $x->operation]);

        return response()->json(['id' => $x->id, 'result' => $x->result]);
    }

    // ------------------------------------------------------------------ apoio

    /** @return array<string,mixed> */
    private function saveConnection(IntegrationConnection $c, Request $request): array
    {
        $in = $request->all();
        $errors = [];
        $isNew = ! $c->exists;
        if ($isNew) {
            $key = strtolower(trim((string) ($in['key'] ?? '')));
            if (! preg_match('/^[a-z][a-z0-9_\-]{1,39}$/', $key) || IntegrationConnection::where('key', $key)->exists()) {
                $errors['key'][] = 'Identificador inválido ou já usado (letras minúsculas, números, _ ou -).';
            }
            $c->key = $key;
        }
        foreach (['name' => 120, 'system' => 40] as $f => $max) {
            if (array_key_exists($f, $in) || $isNew) {
                $v = trim(strip_tags((string) ($in[$f] ?? '')));
                if ($v === '' || mb_strlen($v) > $max) {
                    $errors[$f][] = 'Obrigatório (máx. '.$max.' caracteres).';
                }
                $c->{$f} = $v;
            }
        }
        foreach (['base_url' => 'baseUrl', 'openapi_url' => 'openapiUrl'] as $col => $f) {
            if (array_key_exists($f, $in) || ($isNew && $col === 'base_url')) {
                $url = rtrim(trim((string) ($in[$f] ?? '')), '/');
                if ($url === '' && $col === 'openapi_url') {
                    $c->{$col} = null;

                    continue;
                }
                if (! filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url) || mb_strlen($url) > 300 || parse_url($url, PHP_URL_USER)) {
                    $errors[$f][] = 'URL inválida (https://host/caminho, sem usuário/senha).';
                }
                $c->{$col} = $url;
            }
        }
        if (array_key_exists('environment', $in)) {
            in_array($in['environment'], IntegrationConnection::ENVIRONMENTS, true) ? $c->environment = (string) $in['environment'] : $errors['environment'][] = 'Ambiente inválido.';
        }
        if (array_key_exists('timeoutSeconds', $in)) {
            $t = (int) $in['timeoutSeconds'];
            $t >= 2 && $t <= 120 ? $c->timeout_seconds = $t : $errors['timeoutSeconds'][] = 'Entre 2 e 120 segundos.';
        }
        if (array_key_exists('authType', $in)) {
            in_array($in['authType'], IntegrationConnection::AUTH_TYPES, true) ? $c->auth_type = (string) $in['authType'] : $errors['authType'][] = 'Tipo de autenticação inválido.';
        }
        if (array_key_exists('authConfig', $in) && is_array($in['authConfig'])) {
            $cfg = [];
            foreach (['loginPath' => 200, 'userName' => 120, 'userField' => 60, 'passwordField' => 60, 'tokenPath' => 120, 'headerName' => 60,
                'prefix' => 30, 'tokenUrl' => 300, 'clientId' => 120, 'scope' => 300, 'testPath' => 300] as $k => $max) {
                $v = trim((string) ($in['authConfig'][$k] ?? ''));
                if ($v !== '') {
                    $cfg[$k] = mb_substr($v, 0, $max);
                }
            }
            if (isset($cfg['loginPath']) && ! str_starts_with($cfg['loginPath'], '/')) {
                $errors['authConfig.loginPath'][] = 'Caminho relativo começando com "/" (ex.: /v1/User/login).';
            }
            if (isset($cfg['testPath']) && (! preg_match('#^/[A-Za-z0-9_.~\-/%{}]*$#', $cfg['testPath']) || str_contains($cfg['testPath'], '..'))) {
                $errors['authConfig.testPath'][] = 'Caminho relativo de um GET leve, começando com "/" (ex.: /v1/Financeiro/Moeda).';
            }
            if (isset($cfg['prefix']) && ! preg_match('/^[A-Za-z0-9_\-]{1,30}$/', $cfg['prefix'])) {
                $errors['authConfig.prefix'][] = 'Prefixo inválido (ex.: Bearer, Token).';
            }
            if (isset($cfg['headerName']) && (! preg_match('/^[A-Za-z0-9\-]{1,60}$/', $cfg['headerName']) || in_array(strtolower($cfg['headerName']), ['cookie', 'host', 'content-length', 'transfer-encoding'], true))) {
                $errors['authConfig.headerName'][] = 'Nome de cabeçalho inválido.';
            }

            if (isset($in['authConfig']['tokenTtlMinutes'])) {
                $cfg['tokenTtlMinutes'] = max(1, min(1440, (int) $in['authConfig']['tokenTtlMinutes']));
            }
            $c->auth_config = $cfg;
        }
        // requisitos por tipo de autenticação, sobre o estado final da conexão
        $cfgFinal = (array) ($c->auth_config ?? []);
        if ($c->auth_type === 'basic' && empty($cfgFinal['userName'])) {
            $errors['authConfig.userName'][] = 'Informe o usuário (Basic).';
        }
        if ($c->auth_type === 'oauth2_client_credentials') {
            $tokenUrl = (string) ($cfgFinal['tokenUrl'] ?? '');
            if ($tokenUrl === '' || ! filter_var($tokenUrl, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($tokenUrl), 'https://')) {
                $errors['authConfig.tokenUrl'][] = 'Informe a URL https do endpoint de token (ex.: https://login.microsoftonline.com/<tenant>/oauth2/v2.0/token).';
            }
            if (empty($cfgFinal['clientId'])) {
                $errors['authConfig.clientId'][] = 'Informe o client ID.';
            }
        }
        if (array_key_exists('allowWrites', $in)) {
            $c->allow_writes = (bool) $in['allowWrites'];
            $c->writes_approval = mb_substr(trim((string) ($in['writesApproval'] ?? $c->writes_approval ?? '')), 0, 200) ?: null;
            if ($c->allow_writes && ! $c->writes_approval) {
                $errors['writesApproval'][] = 'Informe a referência da aprovação (chamado/ata) para liberar escritas.';
            }
        }
        if (array_key_exists('enabled', $in)) {
            $c->enabled = (bool) $in['enabled'];
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }
        $before = $c->exists ? $c->only(['name', 'system', 'base_url', 'environment', 'enabled', 'timeout_seconds', 'auth_type', 'auth_config', 'openapi_url', 'allow_writes', 'writes_approval']) : [];
        $c->updated_by = $this->user()->id;
        $c->save();
        $secret = (string) ($in['secret'] ?? '');
        $fileRef = trim((string) ($in['secretFileRef'] ?? ''));
        if ($secret !== '' || $fileRef !== '') {
            if ($fileRef !== '' && ! SecretStore::isValidFileRef($fileRef)) {
                throw ApiProblem::validation(['secretFileRef' => ['Use file:<nome> de um segredo montado.']]);
            }
            $old = $c->secret_ref;
            $c->secret_ref = $fileRef !== '' ? $fileRef : $this->secrets->put('integration:'.$c->key, $secret, $this->user(), $old && str_starts_with($old, 'db:') ? $old : null);
            $c->save();
            AuditLogger::record('integration.secret_updated', 'integration_connection', $c->id, ['connection' => $c->key], null, $this->user(), context: ['object_name' => $c->name]);
        }
        AuditLogger::record('integration.connection_saved', 'integration_connection', $c->id, ['key' => $c->key, 'created' => $isNew], null, $this->user(),
            diff: AuditDiff::between($before, $c->only(array_keys($before ?: ['name' => 1, 'system' => 1, 'base_url' => 1, 'environment' => 1, 'enabled' => 1, 'auth_type' => 1, 'openapi_url' => 1, 'allow_writes' => 1]))), context: ['object_name' => $c->name]);

        return $this->presentConnection($c->loadCount('endpoints'));
    }

    /** @return array<string,mixed> */
    private function saveEndpoint(IntegrationEndpoint $e, Request $request): array
    {
        $in = $request->all();
        $errors = [];
        $isNew = ! $e->exists;
        $manual = $e->source === 'manual';
        if ($isNew) {
            $key = trim((string) ($in['key'] ?? ''));
            if (! preg_match(IntegrationEndpoint::KEY_PATTERN, $key) || IntegrationEndpoint::where('key', $key)->exists()) {
                $errors['key'][] = 'Identificador inválido ou já usado (3–80: letras, números, ponto, _ ou -; começa com letra).';
            }
            $e->key = $key;
        }
        if (array_key_exists('name', $in) || $isNew) {
            $name = trim(strip_tags((string) ($in['name'] ?? '')));
            $name === '' || mb_strlen($name) > 200 ? $errors['name'][] = 'Obrigatório (máx. 200).' : $e->name = $name;
        }
        foreach (['description' => 2000, 'tag' => 80] as $f => $max) {
            if (array_key_exists($f, $in)) {
                $e->{$f} = mb_substr(trim(strip_tags((string) $in[$f])), 0, $max) ?: null;
            }
        }
        if ($manual) {
            if (array_key_exists('method', $in) || $isNew) {
                $m = strtoupper((string) ($in['method'] ?? 'GET'));
                in_array($m, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) ? $e->method = $m : $errors['method'][] = 'Método inválido.';
            }
            if (array_key_exists('path', $in) || $isNew) {
                $p = '/'.ltrim(trim((string) ($in['path'] ?? '')), '/');
                preg_match('#^/[A-Za-z0-9_.~\-/{}%]*$#', $p) && mb_strlen($p) <= 300 ? $e->path = $p : $errors['path'][] = 'Caminho inválido (ex.: /v1/Fornecedores/{CPF_CNPJ}).';
            }
        }
        if (array_key_exists('params', $in) && is_array($in['params'])) {
            $params = [];
            $names = [];
            foreach (array_slice($in['params'], 0, 40) as $i => $p) {
                $name = trim((string) ($p['name'] ?? ''));
                $loc = (string) ($p['in'] ?? 'query');
                $mode = ($p['mode'] ?? 'input') === 'constant' ? 'constant' : 'input';
                $constant = $mode === 'constant' ? mb_substr((string) ($p['constant'] ?? ''), 0, 200) : null;
                if (! preg_match('/^[A-Za-z_][A-Za-z0-9_.\-]{0,79}$/', $name) || ! in_array($loc, IntegrationEndpoint::PARAM_LOCATIONS, true) || in_array($loc.':'.$name, $names, true)) {
                    $errors["params.$i"][] = 'Parâmetro inválido ou repetido.';

                    continue;
                }
                if ($mode === 'constant' && preg_match(self::AUTH_LIKE, $name)) {
                    $errors["params.$i"][] = "\"$name\" parece credencial: não use valor constante — configure a autenticação da conexão (o valor fica no cofre).";

                    continue;
                }
                if ($loc === 'header' && preg_match('/^(authorization|cookie|proxy-authorization|x-api-key|api-key|apikey)$/i', $name)) {
                    $errors["params.$i"][] = 'Cabeçalho de credencial é definido pela autenticação da conexão.';

                    continue;
                }
                $names[] = $loc.':'.$name;
                $params[] = ['name' => $name, 'in' => $loc, 'type' => in_array($t = (string) ($p['type'] ?? 'string'), IntegrationEndpoint::TYPES, true) ? $t : 'string',
                    'required' => $loc === 'path' || ! empty($p['required']), 'mode' => $mode, 'constant' => $constant, 'description' => mb_substr((string) ($p['description'] ?? ''), 0, 300) ?: null];
            }
            $e->params = $params;
        }
        preg_match_all('/\{([^}]+)\}/', (string) $e->path, $mm);
        foreach ($mm[1] as $pathParam) {
            if (! collect((array) ($e->params ?? []))->contains(fn ($p) => $p['name'] === $pathParam && $p['in'] === 'path')) {
                $errors['params'][] = "O caminho usa {{$pathParam}}: inclua o parâmetro de caminho \"$pathParam\".";
            }
        }
        // exemplo colado: infere os campos se a tela não mandou campos (ou mandou a lista vazia — exemplo colado sem clicar em "Inferir")
        $inferredFromSample = false;
        if (array_key_exists('sample', $in) && trim((string) $in['sample']) !== '') {
            $json = json_decode((string) $in['sample'], true, 128);
            if ($json === null) {
                $errors['sample'][] = 'Exemplo de resposta não é JSON válido.';
            } else {
                $data = JsonPaths::get($json, (string) ($in['resultPath'] ?? $e->result_path ?? ''));
                $e->response_sample = JsonPaths::maskSample($data);
                if (empty($in['responseFields'])) {
                    $inf = JsonPaths::infer($data);
                    $e->response_fields = $inf['fields'];
                    $e->response_is_list = $inf['isList'];
                    $inferredFromSample = true;
                }
            }
        }
        if (! $inferredFromSample && array_key_exists('responseFields', $in) && is_array($in['responseFields'])) {
            $fields = [];
            foreach (array_slice($in['responseFields'], 0, 300) as $f) {
                $path = trim((string) ($f['path'] ?? ''));
                if ($path === '' || ! preg_match('/^[A-Za-z0-9_.\[\]\-$]{1,200}$/', $path)) {
                    continue;
                }
                $fields[] = ['path' => $path, 'label' => mb_substr(trim(strip_tags((string) ($f['label'] ?? $path))), 0, 120) ?: $path,
                    'type' => in_array($t = (string) ($f['type'] ?? 'string'), IntegrationEndpoint::TYPES, true) ? $t : 'string', 'sensitive' => (bool) ($f['sensitive'] ?? false), 'hint' => mb_substr((string) ($f['hint'] ?? ''), 0, 60) ?: null];
            }
            $e->response_fields = $fields;
        }
        if (array_key_exists('responseIsList', $in)) {
            $e->response_is_list = (bool) $in['responseIsList'];
        }
        if (array_key_exists('resultPath', $in)) {
            $rp = trim((string) $in['resultPath']);
            $rp === '' || preg_match('/^[A-Za-z0-9_.\[\]\-$]{1,120}$/', $rp) ? $e->result_path = ($rp ?: null) : $errors['resultPath'][] = 'Caminho inválido (ex.: data ou result.items).';
        }
        if (array_key_exists('timeoutSeconds', $in)) {
            $t = $in['timeoutSeconds'] === null || $in['timeoutSeconds'] === '' ? null : (int) $in['timeoutSeconds'];
            $t === null || ($t >= 2 && $t <= 120) ? $e->timeout_seconds = $t : $errors['timeoutSeconds'][] = 'Entre 2 e 120 segundos.';
        }
        if (array_key_exists('lookup', $in) && is_array($in['lookup'])) {
            $l = $in['lookup'];
            $expose = (bool) ($l['expose'] ?? false);
            $inputParam = (string) ($l['inputParam'] ?? '');
            $paths = collect((array) ($e->response_fields ?? []))->pluck('path')->all();
            $fields = array_values(array_filter(array_map('strval', (array) ($l['fields'] ?? [])), fn ($p) => in_array($p, $paths, true)));
            if ($expose) {
                if ($e->method !== 'GET') {
                    $errors['lookup'][] = 'Somente endpoints de leitura (GET) podem ser consulta nos formulários.';
                }
                if (! collect((array) ($e->params ?? []))->contains(fn ($p) => $p['name'] === $inputParam && ($p['mode'] ?? 'input') === 'input')) {
                    $errors['lookup.inputParam'][] = 'Escolha o parâmetro que recebe o valor digitado no formulário.';
                }
                if ($paths === []) {
                    // típico dos endpoints do AX: o Swagger não documenta a resposta
                    $errors['lookup.fields'][] = 'Este endpoint ainda não tem campos de saída. Defina-os na etapa "Parâmetros de saída": cole um exemplo da resposta (e clique em Inferir) ou use "Testar endpoint".';
                } elseif ($fields === []) {
                    $errors['lookup.fields'][] = 'Escolha ao menos um campo de saída para preencher o formulário.';
                }
                $sensitive = collect((array) ($e->response_fields ?? []))->whereIn('path', $fields)->where('sensitive', true)->pluck('path')->all();
                if ($sensitive && empty($l['allowSensitive'])) {
                    $errors['lookup.fields'][] = 'Campos sensíveis selecionados ('.implode(', ', $sensitive).'): confirme que o formulário pode exibi-los.';
                }
            }
            $e->lookup = ['expose' => $expose, 'label' => mb_substr(trim(strip_tags((string) ($l['label'] ?? ''))), 0, 120) ?: null, 'inputParam' => $inputParam ?: null, 'fields' => $fields, 'allowSensitive' => (bool) ($l['allowSensitive'] ?? false)];
        }
        if (array_key_exists('enabled', $in)) {
            $e->enabled = (bool) $in['enabled'];
        }
        if ($errors) {
            throw ApiProblem::validation($errors);
        }
        $tracked = ['name', 'method', 'path', 'enabled', 'params', 'response_fields', 'result_path', 'lookup', 'timeout_seconds'];
        $before = $e->exists ? $e->only($tracked) : [];
        $e->version = ($e->version ?? 0) + 1;
        if ($e->contract_status === 'changed' && ! $isNew && array_key_exists('acknowledgeContract', $in)) {
            $e->contract_status = 'ok';
        }
        $e->updated_by = $this->user()->id;
        $e->save();
        AuditLogger::record('integration.endpoint_saved', 'integration_endpoint', $e->id, ['key' => $e->key, 'created' => $isNew, 'version' => $e->version], null, $this->user(),
            diff: AuditDiff::between($before, $e->only($tracked)), context: ['object_name' => $e->name]);

        return $this->presentEndpoint($e->load('connection'), true);
    }

    /** @return array<string,mixed> */
    private function presentConnection(IntegrationConnection $c): array
    {
        $policy = Cache::remember('bpm:integration-policy:'.$c->id.':'.md5($c->base_url), 60, function () use ($c) {
            try {
                TargetPolicy::assertAllowed($c->base_url);

                return ['ok' => true, 'message' => null];
            } catch (IntegrationUnavailable $e) {
                return ['ok' => false, 'message' => $e->getMessage()];
            }
        });

        return [
            'id' => $c->id, 'key' => $c->key, 'name' => $c->name, 'system' => $c->system, 'baseUrl' => $c->base_url, 'environment' => $c->environment, 'enabled' => $c->enabled,
            'timeoutSeconds' => $c->timeout_seconds, 'authType' => $c->auth_type, 'authConfig' => (object) ($c->auth_config ?? []), 'credential' => $this->secrets->describe($c->secret_ref),
            'openapiUrl' => $c->openapi_url, 'openapiTitle' => $c->openapi_title, 'openapiVersion' => $c->openapi_version,
            'openapiImportedAt' => $c->openapi_imported_at?->toIso8601String(), 'openapiCheckedAt' => $c->openapi_checked_at?->toIso8601String(),
            'allowWrites' => $c->allow_writes, 'writesApproval' => $c->writes_approval, 'lastTest' => $c->last_test, 'lastTestedAt' => $c->last_tested_at?->toIso8601String(),
            'lastTestExplain' => ($c->last_test['ok'] ?? true) ? null : IntegrationErrors::explain($c->last_test['code'] ?? null, 'rest'),
            'endpointsCount' => (int) ($c->endpoints_count ?? 0), 'enabledEndpointsCount' => (int) ($c->enabled_endpoints_count ?? 0),
            'changedEndpoints' => IntegrationEndpoint::where('connection_id', $c->id)->whereIn('contract_status', ['changed', 'removed'])->count(),
            'policy' => $policy, 'hostDeclared' => TargetPolicy::isDeclared((string) parse_url($c->base_url, PHP_URL_HOST)),
            'updatedAt' => $c->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,string>  $bound
     * @return array<string,mixed>
     */
    private function presentEndpoint(IntegrationEndpoint $e, bool $detail, array $bound = []): array
    {
        $base = [
            'id' => $e->id, 'key' => $e->key, 'name' => $e->name, 'tag' => $e->tag, 'method' => $e->method, 'path' => $e->path, 'enabled' => $e->enabled,
            'kind' => $e->isWrite() ? 'write' : 'read', 'source' => $e->source, 'contractStatus' => $e->contract_status, 'version' => $e->version,
            'connection' => ['id' => $e->connection->id, 'key' => $e->connection->key, 'name' => $e->connection->name, 'system' => $e->connection->system, 'enabled' => $e->connection->enabled, 'allowWrites' => $e->connection->allow_writes, 'environment' => $e->connection->environment],
            'lookupExposed' => ! empty($e->lookup['expose']), 'boundTo' => $bound[$e->id] ?? null,
            'hasResponseFields' => ! empty($e->response_fields), 'paramsCount' => count((array) ($e->params ?? [])), 'warningsCount' => count((array) ($e->warnings ?? [])),
            'updatedAt' => $e->updated_at?->toIso8601String(),
        ];
        if (! $detail) {
            return $base;
        }
        $forms = FormSchemaVersion::where('schema', 'like', '%"'.FieldCatalog::API_PREFIX.$e->key.'"%')->count();

        return [...$base, 'description' => $e->description, 'operationId' => $e->operation_id, 'params' => $e->params ?? [], 'bodyFields' => $e->body_fields ?? [],
            'responseFields' => $e->response_fields ?? [], 'responseSample' => $e->response_sample, 'responseIsList' => $e->response_is_list, 'resultPath' => $e->result_path,
            'lookup' => $e->lookup ?? ['expose' => false], 'timeoutSeconds' => $e->timeout_seconds, 'warnings' => $e->warnings ?? [], 'usedInForms' => $forms,
            'lookupSourceKey' => FieldCatalog::API_PREFIX.$e->key, 'url' => rtrim($e->connection->base_url, '/').$e->path,
            'bindings' => IntegrationBinding::where('endpoint_id', $e->id)->get(['operation', 'enabled'])->toArray()];
    }

    private function connection(string $id): IntegrationConnection
    {
        return IntegrationConnection::find($id) ?? throw ApiProblem::notFound('Conexão não encontrada.');
    }

    private function endpoint(string $id): IntegrationEndpoint
    {
        return IntegrationEndpoint::with('connection')->find($id) ?? throw ApiProblem::notFound('Endpoint não encontrado.');
    }
}
