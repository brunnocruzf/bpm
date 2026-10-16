<?php

namespace App\Storage\Azure;

/**
 * Assinatura "Shared Key" do Azure Storage (Blob), conforme "Authorize with Shared Key":
 * StringToSign = VERB, 11 cabeçalhos padrão, CanonicalizedHeaders (x-ms-*) e CanonicalizedResource.
 * A chave da conta (base64) nunca sai desta classe nem é registrada.
 */
final class SharedKeySigner
{
    private const STANDARD = ['content-encoding', 'content-language', 'content-length', 'content-md5', 'content-type', 'date', 'if-modified-since', 'if-match', 'if-none-match', 'if-unmodified-since', 'range'];

    public function __construct(private readonly string $account, private readonly string $keyBase64) {}

    /**
     * @param  array<string,string>  $headers  cabeçalhos exatamente como serão enviados
     * @param  array<string,string>  $query  parâmetros da URL (valores decodificados)
     */
    public function authorization(string $method, string $path, array $query, array $headers): string
    {
        $key = base64_decode($this->keyBase64, true);
        if ($key === false || $key === '') {
            throw new AzureBlobException('credential_invalid', 'A chave da conta de armazenamento não está em base64 válido.');
        }
        $signature = base64_encode(hash_hmac('sha256', $this->stringToSign($method, $path, $query, $headers), $key, true));

        return 'SharedKey '.$this->account.':'.$signature;
    }

    /**
     * @param  array<string,string>  $headers
     * @param  array<string,string>  $query
     */
    public function stringToSign(string $method, string $path, array $query, array $headers): string
    {
        $lower = [];
        foreach ($headers as $name => $value) {
            $lower[strtolower($name)] = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        }
        $lines = [strtoupper($method)];
        foreach (self::STANDARD as $name) {
            $value = $lower[$name] ?? '';
            if ($name === 'content-length' && $value === '0') {
                $value = ''; // versões 2015-02-21+: Content-Length zero entra vazio
            }
            if ($name === 'date' && isset($lower['x-ms-date'])) {
                $value = ''; // com x-ms-date, o Date padrão entra vazio
            }
            $lines[] = $value;
        }
        $canonicalHeaders = '';
        $ms = array_filter($lower, fn ($v, $k) => str_starts_with($k, 'x-ms-'), ARRAY_FILTER_USE_BOTH);
        ksort($ms);
        foreach ($ms as $name => $value) {
            $canonicalHeaders .= $name.':'.$value."\n";
        }
        $resource = '/'.$this->account.$path;
        $params = [];
        foreach ($query as $name => $value) {
            $params[strtolower($name)] = $value;
        }
        ksort($params);
        foreach ($params as $name => $value) {
            $resource .= "\n".$name.':'.$value;
        }

        return implode("\n", $lines)."\n".$canonicalHeaders.$resource;
    }
}
