<?php

namespace App\Mail;

/**
 * Política de destino SMTP (anti-SSRF): somente portas de submissão conhecidas, nada de
 * endpoints de metadata, loopback, link-local ou redes privadas — exceto relays internos
 * declarados pela plataforma (BPM_MAIL_PRIVATE_RELAY_HOSTS), que não são editáveis pela UI.
 * Em produção, complemente com allowlist de egress no firewall/proxy (DNS rebinding).
 */
final class HostPolicy
{
    private const METADATA_HOSTS = ['metadata.google.internal', 'metadata', 'metadata.azure.com', 'instance-data', 'kubernetes.default', 'kubernetes.default.svc'];

    public static function isPrivateRelay(string $host): bool
    {
        return in_array(strtolower($host), array_map('strtolower', (array) config('bpm.mail.private_relay_hosts', [])), true);
    }

    /** @throws MailException */
    public static function assertAllowed(string $host, int $port, string $encryption, bool $resolve = true): void
    {
        $host = strtolower(trim($host));
        if ($host === '' || strlen($host) > 253 || ! preg_match('/^[a-z0-9.\-:\[\]]+$/', $host)) {
            throw new MailException('host_blocked', 'Host SMTP inválido.');
        }
        if (! in_array($port, (array) config('bpm.mail.allowed_smtp_ports', [25, 465, 587, 2525]), true)) {
            throw new MailException('host_blocked', 'Porta SMTP não permitida (use 587 STARTTLS, 465 TLS, 25 ou 2525).');
        }
        $relay = self::isPrivateRelay($host);
        if ($encryption === 'none' && ! $relay) {
            throw new MailException('host_blocked', 'SMTP sem criptografia só é aceito para relay interno declarado pela plataforma.');
        }
        if (in_array($host, self::METADATA_HOSTS, true)) {
            throw new MailException('host_blocked', 'Destino SMTP não permitido.');
        }
        if ($relay) {
            return;
        }
        if (str_ends_with($host, '.internal') || str_ends_with($host, '.local') || str_ends_with($host, '.localhost') || $host === 'localhost') {
            throw new MailException('host_blocked', 'Destino SMTP interno não declarado pela plataforma.');
        }
        $ips = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) ? [trim($host, '[]')] : ($resolve ? self::resolve($host) : []);
        if ($resolve && $ips === []) {
            throw new MailException('connection_failed', 'Não foi possível resolver o host SMTP.', true);
        }
        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new MailException('host_blocked', 'Destino SMTP aponta para rede interna/reservada; use um relay declarado pela plataforma.');
            }
        }
    }

    public static function isPublicIp(string $ip): bool
    {
        if (in_array($ip, ['169.254.169.254', '100.100.100.200', 'fd00:ec2::254'], true)) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
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
