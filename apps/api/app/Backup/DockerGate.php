<?php

namespace App\Backup;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Único caminho até o Docker: o filtro docker-gate (nginx), que libera parar, iniciar e consultar os serviços da própria
 * stack e exportar imagens. Todo o resto é 403 lá; esta classe não precisa, nem deve, pedir outra coisa.
 */
class DockerGate
{
    public function stop(string $service): void
    {
        $this->action($service, 'stop?t=30');
    }

    public function start(string $service): void
    {
        $this->action($service, 'start');
    }

    /** @return array{id:string, image:string, ref:string}|null null quando o serviço não existe nesta stack (perfil desligado) */
    public function image(string $service): ?array
    {
        $response = $this->send(fn () => $this->http()->get('containers/'.$this->container($service).'/json'));
        if ($response->status() === 404) {
            return null;
        }
        $this->assertOk($response, "consulta de $service");

        return ['id' => (string) $response->json('Id'), 'image' => (string) $response->json('Image'), 'ref' => (string) $response->json('Config.Image')];
    }

    /** Exporta a imagem pelo ID: nem toda imagem presa por digest tem tag local. */
    public function exportImage(string $imageId, string $file): void
    {
        $response = $this->send(fn () => $this->http()->timeout(3600)->sink($file)->get('images/'.$imageId.'/get'));
        $this->assertOk($response, 'exportação de imagem');
    }

    private function action(string $service, string $path): void
    {
        $response = $this->send(fn () => $this->http()->timeout(90)->post('containers/'.$this->container($service).'/'.$path));
        // 304: já estava no estado pedido
        if ($response->status() !== 304) {
            $this->assertOk($response, "comando em $service");
        }
    }

    private function container(string $service): string
    {
        return config('bpm.backup.stack').'-'.$service.'-1';
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) config('bpm.backup.docker_url'))->connectTimeout(5)->timeout(30);
    }

    /** @param callable(): Response $request */
    private function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException) {
            throw new BackupFailure('docker_gate_unavailable', 'O filtro docker-gate não respondeu. Confira se o serviço está no ar.');
        }
    }

    private function assertOk(Response $response, string $what): void
    {
        if (! $response->successful()) {
            throw new BackupFailure('docker_gate_refused', "O Docker recusou a $what (HTTP {$response->status()}).");
        }
    }
}
