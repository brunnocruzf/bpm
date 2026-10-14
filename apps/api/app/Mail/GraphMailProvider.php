<?php

namespace App\Mail;

use App\Identity\EntraAppTokenProvider;
use App\Identity\Graph\GraphException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Microsoft 365 via Graph sendMail (permissão de aplicação Mail.Send, com acesso restrito à caixa
 * de envio por RBAC for Applications / Application Access Policy no Exchange Online). Não usa senha
 * de mailbox. Sem anexos; HTML já escapado pelo renderizador de templates.
 */
class GraphMailProvider implements MailProvider
{
    /** @param array{tenantId:string, clientId:string, credentialType:string, credentialRef:?string, authorityHost:string, senderMailbox:string, saveToSentItems:bool} $cfg */
    public function __construct(
        private readonly array $cfg,
        private readonly EntraAppTokenProvider $tokens,
        private readonly ?string $replyTo,
        private readonly int $timeout,
    ) {}

    public function name(): string
    {
        return 'm365_graph';
    }

    public function send(OutgoingMail $mail): MailResult
    {
        $started = microtime(true);
        $token = $this->token();
        $requestId = (string) Str::uuid();
        $message = [
            'subject' => $mail->subject,
            'body' => ['contentType' => 'HTML', 'content' => $mail->html],
            'toRecipients' => [['emailAddress' => array_filter(['address' => $mail->toAddress, 'name' => $mail->toName])]],
            'internetMessageHeaders' => array_filter([
                ['name' => 'X-BPM-Message-Key', 'value' => $mail->messageId()],
                $mail->correlationId ? ['name' => 'X-BPM-Correlation-Id', 'value' => $mail->correlationId] : null,
            ]),
        ];
        if ($this->replyTo) {
            $message['replyTo'] = [['emailAddress' => ['address' => $this->replyTo]]];
        }
        try {
            $response = Http::withToken($token)->timeout($this->timeout)->connectTimeout(5)
                ->withHeaders(['client-request-id' => $requestId])
                ->post('https://graph.microsoft.com/v1.0/users/'.rawurlencode($this->cfg['senderMailbox']).'/sendMail', [
                    'message' => $message,
                    'saveToSentItems' => $this->cfg['saveToSentItems'],
                ]);
        } catch (ConnectionException) {
            throw new MailException('timeout', 'Tempo esgotado ao chamar o Microsoft Graph.', true);
        }
        if ($response->status() !== 202 && ! $response->successful()) {
            $code = (string) $response->json('error.code');
            throw match (true) {
                $response->status() === 401 => new MailException('credential_invalid', 'Token do aplicativo recusado pelo Graph.'),
                $response->status() === 403 => new MailException('consent_missing', 'Sem Mail.Send com consentimento ou caixa fora da política de acesso do aplicativo.'),
                $response->status() === 404 || $code === 'ErrorInvalidUser' => new MailException('sender_invalid', 'Caixa de envio não encontrada no Exchange Online.'),
                $code === 'ErrorInvalidRecipients' => new MailException('recipient_rejected', 'Destinatário recusado pelo Exchange Online.'),
                $response->status() === 429 => new MailException('throttled', 'Graph limitou os envios (429).', true),
                $response->status() >= 500 => new MailException('provider_error', 'Graph indisponível (HTTP '.$response->status().').', true),
                default => new MailException('provider_error', 'Graph recusou o envio (HTTP '.$response->status().($code ? ", $code" : '').').'),
            };
        }

        return new MailResult('m365_graph', (string) ($response->header('request-id') ?: $requestId), (int) round((microtime(true) - $started) * 1000));
    }

    public function test(): array
    {
        $t = microtime(true);
        try {
            $token = $this->token();
        } catch (MailException $e) {
            return [['name' => 'Token app-only do Entra ID', 'ok' => false, 'code' => $e->errorCode, 'message' => $e->getMessage(), 'durationMs' => (int) round((microtime(true) - $t) * 1000)]];
        }
        $ms = (int) round((microtime(true) - $t) * 1000);
        $roles = EntraAppTokenProvider::grantedRoles($token);
        $ok = in_array('Mail.Send', $roles, true);

        return [
            ['name' => 'Token app-only do Entra ID', 'ok' => true, 'code' => null, 'message' => null, 'durationMs' => $ms, 'detail' => 'Permissões no token: '.($roles ? implode(', ', $roles) : 'nenhuma')],
            ['name' => 'Permissão Mail.Send com consentimento', 'ok' => $ok, 'code' => $ok ? null : 'consent_missing', 'message' => $ok ? 'A caixa de envio é confirmada pelo e-mail de teste.' : 'Conceda Mail.Send (Application) com admin consent e restrinja à caixa de envio.'],
        ];
    }

    private function token(): string
    {
        try {
            return $this->tokens->token($this->cfg, 'https://graph.microsoft.com/.default');
        } catch (GraphException $e) {
            throw new MailException($e->errorCode, $e->getMessage(), $e->transient);
        }
    }
}
