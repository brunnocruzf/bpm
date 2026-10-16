<?php

namespace App\Identity\Graph;

/**
 * Leitura do Microsoft Graph v1.0 (somente GET; nenhuma escrita no diretório).
 * Implementações: HttpGraphClient (real, app-only) e MockGraphClient (diretório fictício local).
 */
interface GraphClient
{
    /**
     * GET em URL absoluta (nextLink/deltaLink) ou caminho relativo a /v1.0.
     *
     * @return array<string,mixed> corpo JSON
     *
     * @throws GraphException
     */
    public function get(string $pathOrUrl): array;

    /**
     * GET binário (foto de perfil). Recusa respostas acima de $maxBytes.
     *
     * @return array{bytes:string, contentType:string}
     *
     * @throws GraphException
     */
    public function getBinary(string $pathOrUrl, int $maxBytes): array;
}
