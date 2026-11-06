<?php

namespace Tests\Integration;

use App\Storage\Azure\AzureBlobAdapter;
use App\Storage\Azure\AzureBlobClient;
use App\Storage\Azure\AzureBlobException;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use Tests\TestCase;

/**
 * Valida a assinatura Shared Key de ponta a ponta contra o emulador Azurite (nunca contra o Azure real).
 * Só roda com AZURITE_IT_ENDPOINT=http://127.0.0.1:10000/devstoreaccount1 (suba o serviço `azurite` do Compose de dev).
 * A chave é a chave pública documentada do emulador; pode ser trocada por AZURITE_IT_KEY.
 */
class AzuriteBlobTest extends TestCase
{
    private const WELL_KNOWN_KEY = 'Eby8vdM02xNOcqFlqUwJPLlmEtlCDXJ1OUzFT50uSRZ6IFsuFq2UVErCz4I6tq/K1SZFPTOtr/KBHBeksoGMGw==';

    private AzureBlobClient $client;

    private string $container;

    protected function setUp(): void
    {
        parent::setUp();
        $endpoint = (string) getenv('AZURITE_IT_ENDPOINT');
        if ($endpoint === '') {
            $this->markTestSkipped('Defina AZURITE_IT_ENDPOINT (ex.: http://127.0.0.1:10000/devstoreaccount1) para testar contra o Azurite.');
        }
        $account = trim((string) parse_url($endpoint, PHP_URL_PATH), '/');
        $this->container = 'it'.bin2hex(random_bytes(4));
        $this->client = new AzureBlobClient($account, (string) (getenv('AZURITE_IT_KEY') ?: self::WELL_KNOWN_KEY), $endpoint, $this->container, (string) config('bpm.storage.azure.api_version'));
        $this->client->createContainerIfMissing();
    }

    public function test_put_get_head_delete_and_container_properties_with_a_real_signature(): void
    {
        $this->assertNull($this->client->containerProperties()['publicAccess']);
        $payload = random_bytes(4096);
        $this->client->put('a/b/arquivo com espaço.bin', $payload, 'application/octet-stream');
        $this->assertSame($payload, $this->client->get('a/b/arquivo com espaço.bin'));
        $this->assertSame(4096, $this->client->head('a/b/arquivo com espaço.bin')['size']);
        $this->client->delete('a/b/arquivo com espaço.bin');
        $this->assertNull($this->client->head('a/b/arquivo com espaço.bin'));
        $this->client->delete('a/b/arquivo com espaço.bin'); // idempotente
    }

    public function test_if_none_match_rejects_an_existing_blob_and_a_wrong_key_is_refused(): void
    {
        $this->client->put('prova.bin', 'x', null, true);
        try {
            $this->client->put('prova.bin', 'y', null, true);
            $this->fail('esperava conflito');
        } catch (AzureBlobException $e) {
            $this->assertSame('conflict', $e->errorCode);
        }
        $bad = new AzureBlobClient(trim((string) parse_url((string) getenv('AZURITE_IT_ENDPOINT'), PHP_URL_PATH), '/'), base64_encode(random_bytes(32)), (string) getenv('AZURITE_IT_ENDPOINT'), $this->container, (string) config('bpm.storage.azure.api_version'));
        try {
            $bad->get('prova.bin');
            $this->fail('esperava credencial recusada');
        } catch (AzureBlobException $e) {
            $this->assertSame('credential_invalid', $e->errorCode);
        }
    }

    public function test_a_file_bigger_than_the_memory_limit_goes_through_blocks_and_ranges(): void
    {
        $block = 8 * 1024 * 1024;
        $source = (string) tempnam(sys_get_temp_dir(), 'bpm');
        $copy = (string) tempnam(sys_get_temp_dir(), 'bpm');
        $handle = fopen($source, 'wb');
        for ($i = 0; $i < 37; $i++) {
            fwrite($handle, random_bytes($block));
        }
        fwrite($handle, 'fim');
        fclose($handle);
        $size = (int) filesize($source);
        $this->assertGreaterThan(256 * 1024 * 1024, $size, 'maior que o memory_limit das imagens');
        $before = memory_get_usage(true);

        $this->client->putFile('backups/tmsa/grande.bin', $source, $block);
        $this->client->downloadTo('backups/tmsa/grande.bin', $copy, $block);

        $this->assertLessThan(96 * 1024 * 1024, memory_get_peak_usage(true) - $before, 'o arquivo nunca fica inteiro em memória');
        $this->assertSame($size, $this->client->head('backups/tmsa/grande.bin')['size']);
        $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $copy));
        unlink($source);
        unlink($copy);
    }

    public function test_listing_returns_only_the_prefix_with_sizes(): void
    {
        $this->client->put('backups/tmsa/2026/11/a/manifest.json', '{}');
        $this->client->put('backups/tmsa/2026/11/b/manifest.json', '{"x":1}');
        $this->client->put('attachments/2026/11/anexo.pdf', 'pdf');

        $blobs = $this->client->list('backups/');

        $this->assertSame(['backups/tmsa/2026/11/a/manifest.json', 'backups/tmsa/2026/11/b/manifest.json'], array_column($blobs, 'name'));
        $this->assertSame([2, 7], array_column($blobs, 'size'));
        $this->assertSame([], $this->client->list('nada/'));
    }

    public function test_an_empty_file_is_a_valid_blob(): void
    {
        $source = (string) tempnam(sys_get_temp_dir(), 'bpm');
        $this->client->putFile('vazio.bin', $source, 1024);

        $this->assertSame(0, $this->client->head('vazio.bin')['size']);
        unlink($source);
    }

    public function test_the_flysystem_adapter_applies_the_prefix_and_works_through_laravel_storage_api(): void
    {
        $adapter = new AzureBlobAdapter($this->client, 'bpm');
        $disk = new FilesystemAdapter(new Filesystem($adapter, ['throw' => true]), $adapter, ['throw' => true]);
        $disk->put('attachments/2026/10/x', 'conteúdo');
        $this->assertTrue($disk->exists('attachments/2026/10/x'));
        $this->assertSame('conteúdo', $disk->get('attachments/2026/10/x'));
        $this->assertSame(strlen('conteúdo'), $disk->size('attachments/2026/10/x'));
        $this->assertNotNull($this->client->head('bpm/attachments/2026/10/x'), 'o prefixo vira parte do nome do blob');
        $disk->delete('attachments/2026/10/x');
        $this->assertFalse($disk->exists('attachments/2026/10/x'));
    }
}
