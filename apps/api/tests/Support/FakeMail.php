<?php

namespace Tests\Support;

use App\Mail\MailException;
use App\Mail\MailProvider;
use App\Mail\MailProviderFactory;
use App\Mail\MailResult;
use App\Mail\OutgoingMail;

/**
 * Provedor de e-mail em memória para testes (nenhum envio externo). Permite simular falhas
 * transitórias/permanentes e inspecionar mensagens entregues.
 */
class FakeMail extends MailProviderFactory implements MailProvider
{
    /** @var list<OutgoingMail> */
    public array $sent = [];

    /** @var list<MailException> falhas a lançar nas próximas tentativas (FIFO) */
    public array $failures = [];

    /** @var list<array{name:string, ok:bool, code:?string, message:?string}> */
    public array $testChecks = [['name' => 'Conexão, TLS e autenticação SMTP', 'ok' => true, 'code' => null, 'message' => null]];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(MailProviderFactory::class, $fake);

        return $fake;
    }

    public function __construct() {}

    public function make(array $cfg): MailProvider
    {
        return $this;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function send(OutgoingMail $mail): MailResult
    {
        if ($this->failures) {
            throw array_shift($this->failures);
        }
        $this->sent[] = $mail;

        return new MailResult('fake', 'fake-'.count($this->sent), 3);
    }

    public function test(): array
    {
        return $this->testChecks;
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return array_map(fn (OutgoingMail $m) => $m->toAddress, $this->sent);
    }
}
