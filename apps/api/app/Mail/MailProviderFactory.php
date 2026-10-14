<?php

namespace App\Mail;

use App\Identity\EntraAppTokenProvider;
use App\Identity\Graph\GraphException;
use App\Settings\SecretStore;

/** Constrói o provedor a partir da configuração (substituível em testes via container). */
class MailProviderFactory
{
    public function __construct(private readonly SecretStore $secrets, private readonly EntraAppTokenProvider $tokens) {}

    /** @param array<string,mixed> $cfg valor de EmailSettings */
    public function make(array $cfg): MailProvider
    {
        $from = (string) $cfg['fromAddress'];
        $fromName = (string) ($cfg['fromName'] ?: 'BPM TMSA');
        $replyTo = $cfg['replyTo'] ?: null;
        $timeout = (int) ($cfg['timeout'] ?? 15);
        $m365 = (array) $cfg['m365'];
        $tokenCfg = ['tenantId' => (string) $m365['tenantId'], 'clientId' => (string) $m365['clientId'], 'credentialType' => (string) $m365['credentialType'], 'credentialRef' => $m365['credentialRef'] ?? null, 'authorityHost' => (string) ($m365['authorityHost'] ?? 'login.microsoftonline.com')];

        return match ($cfg['provider']) {
            'smtp' => new SmtpMailProvider('smtp', [
                'host' => (string) $cfg['smtp']['host'], 'port' => (int) $cfg['smtp']['port'], 'encryption' => (string) $cfg['smtp']['encryption'],
                'auth' => (bool) $cfg['smtp']['auth'], 'username' => (string) $cfg['smtp']['username'],
            ], $from, $fromName, $replyTo, $timeout, fn () => $this->secrets->get($cfg['smtp']['passwordRef'] ?? null)),
            'mailpit' => app()->environment('production')
                ? throw new MailException('not_configured', 'Mailpit é proibido em produção.')
                : new SmtpMailProvider('mailpit', ['host' => (string) config('bpm.mail.mailpit_host'), 'port' => (int) config('bpm.mail.mailpit_port'), 'encryption' => 'none', 'auth' => false, 'username' => null],
                    $from, $fromName, $replyTo, $timeout, null, false, false),
            'm365_graph' => new GraphMailProvider([...$tokenCfg, 'senderMailbox' => (string) $m365['senderMailbox'], 'saveToSentItems' => (bool) $m365['saveToSentItems']], $this->tokens, $replyTo, $timeout),
            'm365_smtp_oauth' => new SmtpMailProvider('m365_smtp_oauth', ['host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'starttls', 'auth' => true, 'username' => (string) $m365['senderMailbox']],
                $from, $fromName, $replyTo, $timeout, function () use ($tokenCfg) {
                    try {
                        return $this->tokens->token($tokenCfg, 'https://outlook.office365.com/.default');
                    } catch (GraphException $e) {
                        throw new MailException($e->errorCode, $e->getMessage(), $e->transient);
                    }
                }, true),
            default => throw new MailException('not_configured', 'Envio de e-mail desabilitado.'),
        };
    }
}
