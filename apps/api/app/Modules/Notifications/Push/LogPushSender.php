<?php

namespace App\Modules\Notifications\Push;

use Illuminate\Support\Facades\Log;

/**
 * Writes push messages to the log instead of sending them, until Firebase
 * is connected with the real integrations. It refuses to run in
 * production, where those messages would be lost without a trace.
 */
final class LogPushSender implements PushSender
{
    public function __construct(
        private readonly bool $production,
        private readonly ?string $channel = null,
    ) {}

    public function send(array $tokens, string $title, string $body, array $data = []): ?string
    {
        if ($this->production) {
            throw new PushDeliveryFailed('The log push driver does not send messages. Set PUSH_DRIVER to a real provider in production.');
        }

        $context = ['devices' => count($tokens), 'title' => $title, 'body' => $body, 'data' => $data];
        $this->channel === null
            ? Log::info('Push not sent (log driver)', $context)
            : Log::channel($this->channel)->info('Push not sent (log driver)', $context);

        return null;
    }
}
