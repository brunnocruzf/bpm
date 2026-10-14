<?php

namespace App\Mail;

use Closure;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * SMTP (personalizado, Microsoft 365 com XOAUTH2 ou Mailpit em dev). Validação de certificado TLS
 * sempre ativa (padrão do PHP/Symfony; não há opção para desligar). STARTTLS é exigido quando
 * configurado; TLS implícito na 465.
 */
class SmtpMailProvider implements MailProvider
{
    /**
     * @param  array{host:string, port:int, encryption:string, auth:bool, username:?string}  $smtp
     * @param  Closure():?string|null  $password  senha ou token OAuth (resolvido só no envio)
     */
    public function __construct(
        private readonly string $providerName,
        private readonly array $smtp,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly ?string $replyTo,
        private readonly int $timeout,
        private readonly ?Closure $password = null,
        private readonly bool $oauth = false,
        private readonly bool $checkHost = true,
        private ?TransportInterface $transport = null,
    ) {}

    public function name(): string
    {
        return $this->providerName;
    }

    public function send(OutgoingMail $mail): MailResult
    {
        $started = microtime(true);
        $transport = $this->transport();
        $email = (new Email)
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to(new Address($mail->toAddress, (string) $mail->toName))
            ->subject($mail->subject)
            ->text($mail->text)
            ->html($mail->html);
        if ($this->replyTo) {
            $email->replyTo($this->replyTo);
        }
        $email->getHeaders()->addIdHeader('Message-ID', $mail->messageId());
        if ($mail->correlationId) {
            $email->getHeaders()->addTextHeader('X-BPM-Correlation-Id', $mail->correlationId);
        }
        $email->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');
        try {
            $sent = $transport->send($email);
        } catch (Throwable $e) {
            throw self::classify($e);
        }

        return new MailResult($this->providerName, $sent?->getMessageId() ?? $mail->messageId(), (int) round((microtime(true) - $started) * 1000));
    }

    public function test(): array
    {
        // etapas com duração e detalhe não sensível (exibidas no log da integração)
        $checks = [];
        $target = $this->smtp['host'].':'.$this->smtp['port'].' · '.match ($this->smtp['encryption']) {
            'tls' => 'TLS implícito', 'starttls' => 'STARTTLS', default => 'sem criptografia'
        };
        $stage = 'policy';
        $t = microtime(true);
        try {
            $transport = $this->transport();
            $checks[] = ['name' => 'Destino permitido pela política de rede', 'ok' => true, 'code' => null, 'message' => null, 'detail' => $target, 'durationMs' => self::ms($t)];
            if ($transport instanceof EsmtpTransport) {
                $stage = 'session';
                $t = microtime(true);
                $transport->start();
                $transport->stop();
                $auth = $this->smtp['auth'] ? ($this->oauth ? 'autenticado via OAuth (XOAUTH2)' : 'autenticado com usuário e senha') : 'sem autenticação';
                $checks[] = ['name' => 'Conexão, TLS e autenticação SMTP', 'ok' => true, 'code' => null, 'message' => null, 'detail' => "Sessão SMTP aberta e encerrada ($auth)", 'durationMs' => self::ms($t)];
            }
        } catch (Throwable $e) {
            $err = self::classify($e);
            $name = match (true) {
                $stage === 'policy' && $err->errorCode === 'not_configured' => 'Credencial SMTP',
                $stage === 'policy' => 'Destino permitido pela política de rede',
                default => 'Conexão, TLS e autenticação SMTP',
            };
            $checks[] = ['name' => $name, 'ok' => false, 'code' => $err->errorCode, 'message' => $err->getMessage(), 'detail' => $target, 'durationMs' => self::ms($t)];
        }

        return $checks;
    }

    private static function ms(float $since): int
    {
        return (int) round((microtime(true) - $since) * 1000);
    }

    private function transport(): TransportInterface
    {
        if ($this->transport) {
            return $this->transport;
        }
        $host = $this->smtp['host'];
        $port = (int) $this->smtp['port'];
        $encryption = $this->smtp['encryption'];
        if ($this->checkHost) {
            HostPolicy::assertAllowed($host, $port, $encryption);
        }
        $transport = new EsmtpTransport($host, $port, $encryption === 'tls');
        if ($encryption === 'starttls') {
            $transport->setRequireTls(true);
        } elseif ($encryption === 'none') {
            $transport->setAutoTls(false);
        }
        $stream = $transport->getStream();
        if (method_exists($stream, 'setTimeout')) {
            $stream->setTimeout($this->timeout);
        }
        if ($this->smtp['auth']) {
            $secret = $this->password ? ($this->password)() : null;
            if (! $secret) {
                throw new MailException('not_configured', 'Credencial SMTP não configurada ou ilegível.');
            }
            $transport->setUsername((string) $this->smtp['username']);
            $transport->setPassword($secret);
            if ($this->oauth) {
                $transport->setAuthenticators([new XOAuth2Authenticator]);
            }
        }

        return $this->transport = $transport;
    }

    public static function classify(Throwable $e): MailException
    {
        if ($e instanceof MailException) {
            return $e;
        }
        $msg = strtolower($e->getMessage());
        $code = (int) $e->getCode();

        return match (true) {
            str_contains($msg, 'certificate') || str_contains($msg, 'ssl operation failed') || str_contains($msg, 'starttls') || str_contains($msg, 'crypto') || str_contains($msg, 'handshake') => new MailException('tls_failure', 'Falha na negociação TLS/validação do certificado do servidor SMTP.'),
            $code === 535 || $code === 534 || str_contains($msg, 'authenticat') => new MailException('auth_failed', 'Servidor SMTP recusou a autenticação.'),
            in_array($code, [550, 551, 552, 553, 554], true) => new MailException('recipient_rejected', 'Servidor recusou o destinatário ou a mensagem (código '.$code.').'),
            $code >= 400 && $code < 500 => new MailException('throttled', 'Servidor SMTP pediu nova tentativa (código '.$code.').', true),
            str_contains($msg, 'timed out') || str_contains($msg, 'timeout') => new MailException('timeout', 'Tempo esgotado ao falar com o servidor SMTP.', true),
            str_contains($msg, 'could not be established') || str_contains($msg, 'refused') || str_contains($msg, 'getaddrinfo') || str_contains($msg, 'connection') => new MailException('connection_failed', 'Não foi possível conectar ao servidor SMTP.', true),
            $e instanceof TransportExceptionInterface => new MailException('provider_error', 'Falha no transporte SMTP.', true),
            default => new MailException('provider_error', 'Falha inesperada no envio.', true),
        };
    }
}
