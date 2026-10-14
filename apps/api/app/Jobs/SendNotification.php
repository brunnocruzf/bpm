<?php

namespace App\Jobs;

use App\Notifications\NotificationSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Envia uma mensagem do outbox de e-mail. Retentativas são controladas pelo estado do outbox. */
class SendNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public readonly string $messageId) {}

    public function handle(NotificationSender $sender): void
    {
        $sender->send($this->messageId);
    }
}
