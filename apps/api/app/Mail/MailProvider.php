<?php

namespace App\Mail;

interface MailProvider
{
    public function name(): string;

    /** @throws MailException */
    public function send(OutgoingMail $mail): MailResult;

    /**
     * Teste de conexão real (sem enviar mensagem).
     *
     * @return list<array{name:string, ok:bool, code:?string, message:?string}>
     */
    public function test(): array;
}
