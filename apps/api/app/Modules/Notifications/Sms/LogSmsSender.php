<?php

namespace App\Modules\Notifications\Sms;

use App\Support\Phone\SaudiMobile;
use Illuminate\Support\Facades\Log;

/**
 * Writes messages to the log instead of sending them, for local development
 * and staging until an SMS provider is chosen. Sign-in codes would end up in
 * production logs, so it refuses to run there: sign-in fails with
 * `sms_unavailable` until a real driver is configured.
 */
final class LogSmsSender implements SmsSender
{
    public function __construct(
        private readonly bool $production,
        private readonly ?string $channel = null,
    ) {}

    public function send(string $to, string $message): void
    {
        if ($this->production) {
            throw new SmsDeliveryFailed('The log SMS driver does not send messages. Set SMS_DRIVER to a real provider in production.');
        }

        Log::channel($this->channel)->info('SMS not sent (log driver)', [
            'to' => SaudiMobile::mask($to),
            'message' => $message,
        ]);
    }
}
