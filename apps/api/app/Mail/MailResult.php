<?php

namespace App\Mail;

final class MailResult
{
    public function __construct(
        public readonly string $provider,
        public readonly ?string $providerMessageId,
        public readonly int $durationMs,
    ) {}
}
