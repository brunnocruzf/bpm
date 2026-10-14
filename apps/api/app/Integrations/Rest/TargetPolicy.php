<?php

namespace App\Integrations\Rest;

use App\Integrations\IntegrationUnavailable;
use App\Mail\HostPolicy;

/**
 * Destinos permitidos para chamadas REST do servidor (anti-SSRF):
 *  - somente https (http apenas para host declarado pela plataforma);
 *  - nunca metadata/loopback/link-local;
 *  - host que resolve para rede interna (ex.: apidax.tmsa.ind.br → 10.x) só se declarado em
 *    BPM_INTEGRATION_ALLOWED_HOSTS (não editável pela tela);
 *  - em produção, TODO host precisa estar declarado (governança de egress).
 * Redirecionamentos são desligados nas chamadas (não seguem para outro destino).
 */
final class TargetPolicy
{
    private const BLOCKED = ['metadata.google.internal', 'metadata', 'metadata.azure.com', 'instance-data', 'kubernetes.default', 'kubernetes.default.svc', 'localhost'];

    /** @throws IntegrationUnavailable */
    public static function assertAllowed(string $url, bool $resolve = true): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ! in_array($scheme, ['https', 'http'], true) || isset($parts['user']) || isset($parts['pass'])) {
            throw new IntegrationUnavailable('URL inválida (use https://host/caminho, sem usuário/senha na URL).', 'target_blocked');
        }
        $declared = self::isDeclared($host);
        if ($scheme === 'http' && ! $declared) {
            throw new IntegrationUnavailable('Somente https é permitido (http só para host declarado pela plataforma).', 'target_blocked');
        }
        if (in_array($host, self::BLOCKED, true) || str_ends_with($host, '.localhost')) {
            throw new IntegrationUnavailable('Destino não permitido.', 'target_blocked');
        }
        if ($declared) {
            return;
        }
        if (app()->environment('production')) {
            throw new IntegrationUnavailable("Host $host não está declarado pela plataforma (BPM_INTEGRATION_ALLOWED_HOSTS).", 'target_blocked');
        }
        if (str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            throw new IntegrationUnavailable("Host interno $host não declarado pela plataforma (BPM_INTEGRATION_ALLOWED_HOSTS).", 'target_blocked');
        }
        $ips = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) ? [trim($host, '[]')] : ($resolve ? self::resolve($host) : []);
        if ($resolve && $ips === []) {
            throw new IntegrationUnavailable("Não foi possível resolver o host $host (DNS).", 'connection_failed');
        }
        foreach ($ips as $ip) {
            if (! HostPolicy::isPublicIp($ip)) {
                throw new IntegrationUnavailable("O host $host aponta para a rede interna ($ip): ele precisa ser declarado pela plataforma em BPM_INTEGRATION_ALLOWED_HOSTS.", 'target_blocked');
            }
        }
    }

    public static function isDeclared(string $host): bool
    {
        return in_array(strtolower($host), (array) config('bpm.integrations.allowed_hosts', []), true);
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = [];
        foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $r) {
            if (! empty($r['ip'])) {
                $ips[] = (string) $r['ip'];
            }
            if (! empty($r['ipv6'])) {
                $ips[] = (string) $r['ipv6'];
            }
        }

        return $ips ?: (($v4 = gethostbyname($host)) !== $host ? [$v4] : []);
    }
}
