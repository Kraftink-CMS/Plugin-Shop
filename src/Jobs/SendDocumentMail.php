<?php

namespace KraftInk\Plugins\Shop\Jobs;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use KraftInk\Plugins\Shop\Services\Commerce\DocumentMessages;

class SendDocumentMail implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $messageId, public int $attempt)
    {
    }

    public function handle(DocumentMessages $messages): void
    {
        $messages->send($this->messageId, $this->attempt);
    }
}
