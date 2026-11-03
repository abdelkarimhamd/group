<?php

namespace App\Modules\Notifications\Sms;

/**
 * Sends one text message. Implementations throw SmsDeliveryFailed when the
 * message could not be handed to the provider.
 */
interface SmsSender
{
    /**
     * @param  string  $to  Saudi mobile number in E.164
     * @param  string|null  $reference  our ID for the message, the same on every retry, so a
     *                                  provider that keeps it can drop a repeat (Unifonic's CorrelationID)
     * @return string|null the provider's message ID
     *
     * @throws SmsDeliveryFailed
     */
    public function send(string $to, string $message, ?string $reference = null): ?string;
}
