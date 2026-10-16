<?php

namespace Tests\Unit;

use App\Storage\Azure\AzureBlobException;
use App\Storage\Azure\SharedKeySigner;
use PHPUnit\Framework\TestCase;

class SharedKeySignerTest extends TestCase
{
    public function test_string_to_sign_matches_the_documented_microsoft_example(): void
    {
        // Exemplo de "Authorize with Shared Key" (Get Container Metadata): 11 cabeçalhos vazios, x-ms-*, recurso e query ordenada.
        $signer = new SharedKeySigner('myaccount', base64_encode('chave'));
        $string = $signer->stringToSign('GET', '/mycontainer', ['restype' => 'container', 'comp' => 'metadata', 'timeout' => '20'], [
            'x-ms-date' => 'Fri, 26 Jun 2015 23:39:12 GMT', 'x-ms-version' => '2015-02-21',
        ]);

        $this->assertSame("GET\n\n\n\n\n\n\n\n\n\n\n\nx-ms-date:Fri, 26 Jun 2015 23:39:12 GMT\nx-ms-version:2015-02-21\n/myaccount/mycontainer\ncomp:metadata\nrestype:container\ntimeout:20", $string);
    }

    public function test_content_length_zero_is_empty_x_ms_headers_are_sorted_and_standard_headers_included(): void
    {
        $signer = new SharedKeySigner('acct', base64_encode('k'));
        $string = $signer->stringToSign('PUT', '/c/b.bin', [], [
            'x-ms-version' => '2023-11-03', 'x-ms-blob-type' => 'BlockBlob', 'Content-Length' => '0', 'Content-Type' => 'application/octet-stream', 'If-None-Match' => '*', 'x-ms-date' => 'Mon, 01 Jan 2024 00:00:00 GMT',
        ]);
        $lines = explode("\n", $string);
        $this->assertSame('PUT', $lines[0]);
        $this->assertSame('', $lines[3], 'Content-Length zero entra vazio');
        $this->assertSame('application/octet-stream', $lines[5]);
        $this->assertSame('', $lines[6], 'Date padrão vazio quando há x-ms-date');
        $this->assertSame('*', $lines[9]);
        $this->assertSame(['x-ms-blob-type:BlockBlob', 'x-ms-date:Mon, 01 Jan 2024 00:00:00 GMT', 'x-ms-version:2023-11-03', '/acct/c/b.bin'], array_slice($lines, 12));
    }

    public function test_emulator_resource_contains_the_account_twice_and_content_length_is_kept_when_positive(): void
    {
        $signer = new SharedKeySigner('devstoreaccount1', base64_encode('k'));
        $string = $signer->stringToSign('PUT', '/devstoreaccount1/anexos/a', [], ['Content-Length' => '1024', 'x-ms-date' => 'x']);
        $this->assertStringContainsString("\n1024\n", $string);
        $this->assertStringEndsWith('/devstoreaccount1/devstoreaccount1/anexos/a', $string);
    }

    public function test_authorization_is_base64_hmac_sha256_with_the_decoded_key_and_never_exposes_the_key(): void
    {
        $key = random_bytes(32);
        $signer = new SharedKeySigner('acct', base64_encode($key));
        $headers = ['x-ms-date' => 'Mon, 01 Jan 2024 00:00:00 GMT', 'x-ms-version' => '2023-11-03'];
        $auth = $signer->authorization('GET', '/c/b', [], $headers);

        $expected = base64_encode(hash_hmac('sha256', $signer->stringToSign('GET', '/c/b', [], $headers), $key, true));
        $this->assertSame("SharedKey acct:$expected", $auth);
        $this->assertStringNotContainsString(base64_encode($key), $auth);
    }

    public function test_invalid_base64_key_is_rejected_with_a_stable_code(): void
    {
        $this->expectException(AzureBlobException::class);
        (new SharedKeySigner('acct', '%%%não-é-base64%%%'))->authorization('GET', '/c', [], ['x-ms-date' => 'x']);
    }
}
