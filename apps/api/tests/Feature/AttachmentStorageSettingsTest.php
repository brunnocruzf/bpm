<?php

namespace Tests\Feature;

use App\Models\AppSettingRevision;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\SettingValidation;
use App\Models\StorageTarget;
use App\Settings\Types\AttachmentStorageSettings;
use App\Storage\AttachmentStorage;
use App\Support\ApiProblem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\FakeAzureBlob;
use Tests\TestCase;

class AttachmentStorageSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/settings/attachments-storage';

    private FakeAzureBlob $azure;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('attachments_local');
        $this->seedIdentity();
        Sleep::fake();
        $this->azure = FakeAzureBlob::install();
        config(['bpm.integrations.allowed_hosts' => [FakeAzureBlob::ACCOUNT.'.blob.core.windows.net', 'outraconta.blob.core.windows.net']]);
    }

    /** @param array<string,mixed> $azure */
    private function save(array $azure = [], string $provider = 'azure_blob')
    {
        return $this->as('admin')->putJson(self::URL, ['provider' => $provider, 'azure' => array_merge([
            'accountName' => FakeAzureBlob::ACCOUNT, 'container' => 'anexos', 'pathPrefix' => 'bpm', 'accountKey' => FakeAzureBlob::KEY, 'approvalReference' => 'SEC-2026-77',
        ], $azure)]);
    }

    private function configureAndActivate(array $azure = []): void
    {
        $this->save($azure)->assertOk();
        $this->as('admin')->postJson(self::URL.'/test')->assertOk()->assertJsonPath('lastValidation.code', 'storage_ok');
        $this->as('admin')->postJson(self::URL.'/activate')->assertOk()->assertJsonPath('requiresActivation', true);
    }

    /** @param array<string,mixed> $input */
    private function normalizeErrors(AttachmentStorageSettings $type, array $input): array
    {
        try {
            $type->normalize($input, $type->defaults(), $this->dev('admin'));

            return [];
        } catch (ApiProblem $e) {
            return $e->errors;
        }
    }

    private function upload(string $user = 'cq'): string
    {
        $pdf = UploadedFile::fake()->createWithContent('laudo.pdf', "%PDF-1.4\n%%EOF\n");

        return (string) $this->as($user)->post('/api/v1/attachments', ['file' => $pdf], ['Accept' => 'application/json'])->assertCreated()->json('id');
    }

    private function download(string $id): string
    {
        $url = $this->as('cq')->getJson("/api/v1/attachments/$id/url")->assertOk()->json('url');

        return $this->get($url)->assertOk()->streamedContent();
    }

    public function test_validation_ssrf_and_production_guards(): void
    {
        $this->save(['accountName' => 'Conta_Invalida'])->assertStatus(422)->assertJsonValidationErrors('azure.accountName');
        $this->save(['container' => 'a--b'])->assertStatus(422)->assertJsonValidationErrors('azure.container');
        $this->save(['pathPrefix' => '../fora'])->assertStatus(422)->assertJsonValidationErrors('azure.pathPrefix');
        $this->save(['accountKey' => 'isto não é base64!'])->assertStatus(422)->assertJsonValidationErrors('azure.accountKey');
        $this->save(['endpointMode' => 'emulator'])->assertStatus(422)->assertJsonValidationErrors('azure.endpointMode');
        $this->as('cq')->putJson(self::URL, ['provider' => 'azure_blob'])->assertForbidden();

        // produção: todo host precisa estar declarado pela plataforma e o emulador é proibido
        $type = app(AttachmentStorageSettings::class);
        $this->app['env'] = 'production';
        config(['bpm.integrations.allowed_hosts' => [], 'bpm.storage.emulator.endpoint' => 'http://azurite.test/devstoreaccount1']);
        $errors = fn (array $azure) => $this->normalizeErrors($type, ['provider' => 'azure_blob', 'azure' => $azure + ['container' => 'anexos', 'approvalReference' => 'x']]);
        $this->assertArrayHasKey('azure.accountName', $errors(['accountName' => 'contaqualquer']));
        $this->assertArrayHasKey('azure.endpointMode', $errors(['endpointMode' => 'emulator']));
        config(['bpm.integrations.allowed_hosts' => ['contaqualquer.blob.core.windows.net']]);
        $this->assertSame([], $errors(['accountName' => 'contaqualquer']));
        $this->artisan('bpm:storage-azure-init')->assertExitCode(1);
    }

    public function test_status_follows_completeness_and_secret_is_write_only_and_never_logged(): void
    {
        $this->as('admin')->getJson(self::URL)->assertOk()->assertJsonPath('status', 'disabled')->assertJsonPath('value.effective.provider', 'platform');
        $res = $this->save(['accountKey' => FakeAzureBlob::KEY, 'approvalReference' => ''])->assertOk()->assertJsonPath('status', 'incomplete');
        $this->assertStringContainsString('referência da aprovação', (string) $res->json('statusDetail'));
        $res = $this->save()->assertOk()->assertJsonPath('status', 'test_pending')->assertJsonPath('value.azure.credential.configured', true);
        $this->assertStringNotContainsString(FakeAzureBlob::KEY, $res->getContent());
        $this->assertArrayNotHasKey('credentialRef', $res->json('value.azure'));
        $this->as('admin')->getJson(self::URL)->assertOk()->assertJsonMissingExact(['accountKey' => FakeAzureBlob::KEY]);

        $this->assertTrue(AuditEvent::where('action', 'settings.secret_updated')->exists());
        $this->assertStringNotContainsString(FakeAzureBlob::KEY, json_encode(AuditEvent::all()->toArray()));
        $this->assertStringNotContainsString(FakeAzureBlob::KEY, json_encode(AppSettingRevision::all()->toArray()));
        $this->assertStringNotContainsString(FakeAzureBlob::KEY, json_encode(Storage::fake('attachments_local')->allFiles()));
    }

    public function test_connection_test_really_writes_reads_and_deletes_and_reports_each_step(): void
    {
        $this->save()->assertOk();
        $res = $this->as('admin')->postJson(self::URL.'/test')->assertOk()->assertJsonPath('status', 'configured')->assertJsonPath('lastValidation.code', 'storage_ok');
        $names = array_column($res->json('lastValidation.checks'), 'name');
        $this->assertSame(['Destino permitido pela política de rede', 'Credencial', 'Contêiner existe e é privado', 'Gravação de arquivo de prova', 'Leitura e integridade (SHA-256)', 'Exclusão do arquivo de prova'], $names);
        $this->assertTrue(collect($res->json('lastValidation.checks'))->every(fn ($c) => $c['ok']));
        $verbs = array_map(fn ($r) => explode(' ', $r)[0], $this->azure->requests);
        $this->assertSame(['GET', 'PUT', 'GET', 'DELETE', 'HEAD'], $verbs, 'contêiner, PUT, GET, DELETE e conferência HEAD aconteceram de verdade');
        $this->assertStringContainsString('/anexos/bpm/.bpm-probe/', $this->azure->requests[1]);
        $this->assertSame([], $this->azure->blobs, 'o arquivo de prova é removido');
        $this->assertSame(1, SettingValidation::where('setting_key', 'attachments.storage')->count());
    }

    public function test_connection_test_failures_become_stable_codes_and_no_secret_leaks(): void
    {
        $this->save()->assertOk();
        $code = fn () => $this->as('admin')->postJson(self::URL.'/test')->assertOk()->json('lastValidation.code');

        $this->azure->key = base64_encode('outra-chave-diferente-0123456789');
        $this->assertSame('credential_invalid', $code());
        $this->azure->key = FakeAzureBlob::KEY;

        unset($this->azure->containers['anexos']);
        $this->assertSame('container_not_found', $code());
        $this->azure->containers['anexos'] = true;

        $this->azure->publicAccess = 'blob';
        $this->assertSame('container_public', $code());
        $this->azure->publicAccess = null;

        $this->azure->corruptReads = true;
        $this->assertSame('integrity_mismatch', $code());
        $this->azure->corruptReads = false;
        $this->assertSame([], $this->azure->blobs, 'a limpeza roda mesmo após falha');

        $this->azure->failures = [['status' => 403, 'code' => 'KeyBasedAuthenticationNotPermitted']];
        $this->assertSame('shared_key_disabled', $code());

        $this->azure->failures = [['status' => 403, 'code' => 'AuthorizationFailure', 'body' => '<Error><Code>AuthorizationFailure</Code><Message>Server failed to authenticate the request.</Message></Error>']];
        $this->assertSame('credential_invalid', $code());

        $this->azure->failures = [['status' => 403, 'code' => 'AuthorizationFailure', 'body' => '<Error><Code>AuthorizationFailure</Code><Message>This request is not authorized to perform this operation.</Message></Error>']];
        $this->assertSame('storage_forbidden', $code());

        $log = $this->as('admin')->getJson('/api/v1/admin/settings/logs/storage')->assertOk();
        $this->assertStringNotContainsString(FakeAzureBlob::KEY, $log->getContent());
        $this->as('admin')->getJson(self::URL)->assertJsonPath('status', 'error');
        $this->azure->failures = [];
        $this->as('admin')->postJson(self::URL.'/activate')->assertStatus(409)->assertJsonPath('code', 'validation_required');
    }

    public function test_activation_requires_a_passing_test_on_the_same_version_and_editing_keeps_the_active_target(): void
    {
        $this->as('admin')->postJson(self::URL.'/activate')->assertStatus(409);
        $this->save()->assertOk();
        $this->as('admin')->postJson(self::URL.'/activate')->assertStatus(409);
        $this->as('admin')->postJson(self::URL.'/test')->assertOk();
        $this->as('admin')->postJson(self::URL.'/activate')->assertOk()->assertJsonPath('value.effective.provider', 'azure_blob')->assertJsonPath('value.effective.container', 'anexos');
        $this->assertNotNull($this->as('admin')->getJson(self::URL)->json('activatedAt'));

        // editar muda a versão: exige novo teste/ativação, mas o destino em vigor não muda
        $this->save(['pathPrefix' => 'outro'])->assertOk()->assertJsonPath('activatedAt', null);
        $this->as('admin')->postJson(self::URL.'/activate')->assertStatus(409);
        $this->assertSame('bpm', $this->as('admin')->getJson(self::URL)->json('value.effective.pathPrefix'));
        $this->assertSame(1, StorageTarget::where('status', 'active')->count());
        $this->as('cq')->postJson(self::URL.'/activate')->assertForbidden();
    }

    public function test_new_uploads_go_to_azure_old_ones_stay_readable_and_deactivating_returns_to_the_platform_disk(): void
    {
        $old = $this->upload();
        $this->assertSame('attachments_local', Attachment::findOrFail($old)->disk);

        $this->configureAndActivate();
        $new = $this->upload();
        $row = Attachment::findOrFail($new);
        $this->assertStringStartsWith('azblob_', $row->disk);
        $this->assertLessThanOrEqual(40, strlen($row->disk));
        $this->assertArrayHasKey('anexos/bpm/'.$row->object_key, $this->azure->blobs, 'o prefixo é transparente e o blob existe no contêiner');
        $this->assertSame("%PDF-1.4\n%%EOF\n", $this->download($new));
        $this->assertSame("%PDF-1.4\n%%EOF\n", $this->download($old));
        $this->assertSame('azblob_', substr(app(AttachmentStorage::class)->currentDisk(), 0, 7));

        $this->as('admin')->postJson(self::URL.'/deactivate')->assertOk()->assertJsonPath('activatedAt', null)->assertJsonPath('value.effective.provider', 'platform');
        $after = $this->upload();
        $this->assertSame('attachments_local', Attachment::findOrFail($after)->disk);
        $this->assertSame("%PDF-1.4\n%%EOF\n", $this->download($new), 'o destino aposentado continua legível');

        $usage = collect($this->as('admin')->getJson(self::URL.'/usage')->assertOk()->json('data'));
        $this->assertSame(2, $usage->count());
        $this->assertSame('retired', $usage->firstWhere('files', 1)['disk'] === $row->disk ? $usage->firstWhere('disk', $row->disk)['status'] : 'x');
        $this->assertTrue(AuditEvent::where('action', 'settings.activated')->exists());
        $this->assertTrue(AuditEvent::where('action', 'settings.disabled')->exists());
    }

    public function test_key_rotation_keeps_the_target_and_a_different_container_creates_a_new_one(): void
    {
        $this->configureAndActivate();
        $first = StorageTarget::firstOrFail();
        $id = $this->upload();

        $newKey = base64_encode('chave-rotacionada-0123456789abcdefgh');
        $this->azure->key = $newKey;
        $this->configureAndActivate(['accountKey' => $newKey]);
        $this->assertSame(1, StorageTarget::count());
        $this->assertSame($first->id, StorageTarget::firstOrFail()->id);
        $this->assertSame("%PDF-1.4\n%%EOF\n", $this->download($id), 'anexo antigo legível com a chave rotacionada');

        $this->azure->containers['novo'] = true;
        $this->configureAndActivate(['container' => 'novo', 'accountKey' => $newKey]);
        $this->assertSame(2, StorageTarget::count());
        $this->assertSame(1, StorageTarget::where('status', 'active')->count());
        $this->assertSame("%PDF-1.4\n%%EOF\n", $this->download($id), 'o destino aposentado continua legível');
    }

    public function test_upload_failure_is_a_503_without_falling_back_and_cleanup_survives_a_broken_target(): void
    {
        $this->configureAndActivate();
        $this->azure->failures = [['status' => 503, 'code' => 'ServerBusy'], ['status' => 503, 'code' => 'ServerBusy'], ['status' => 503, 'code' => 'ServerBusy']];
        $pdf = UploadedFile::fake()->createWithContent('laudo.pdf', "%PDF-1.4\n%%EOF\n");
        $this->as('cq')->post('/api/v1/attachments', ['file' => $pdf], ['Accept' => 'application/json'])->assertStatus(503)->assertJsonPath('code', 'storage_unavailable');
        $this->assertSame(0, Attachment::count());
        $this->assertSame([], Storage::disk('attachments_local')->allFiles('attachments'), 'nenhum fallback silencioso para outro disco');

        $kept = $this->upload();
        $gone = $this->upload();
        Attachment::whereIn('id', [$kept, $gone])->update(['created_at' => now()->subDays(3)]);
        $this->azure->failures = [['status' => 403, 'code' => 'AuthorizationFailure']];
        Log::spy();
        Artisan::call('bpm:cleanup-orphans');
        $this->assertSame(1, Attachment::whereIn('id', [$kept, $gone])->count(), 'a falha em um anexo não impede os demais');
    }
}
