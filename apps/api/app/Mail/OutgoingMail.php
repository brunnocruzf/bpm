<?php

namespace App\Mail;

/** Mensagem a enviar (texto simples + HTML escapado). Sem anexos por padrão. */
final class OutgoingMail
{
    public function __construct(
        public readonly string $toAddress,
        public readonly ?string $toName,
        public readonly string $subject,
        public readonly string $text,
        public readonly string $html,
        public readonly string $messageKey,
        public readonly ?string $correlationId = null,
    ) {}

    /** Message-ID determinístico: reenvios da mesma notificação mantêm o mesmo identificador. */
    public function messageId(): string
    {
        return substr(hash('sha256', $this->messageKey), 0, 32).'@bpm.tmsa';
    }
}
