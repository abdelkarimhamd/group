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
     * @return string|null the provider's message ID
     *
     * @throws SmsDeliveryFailed
     */
    public function send(string $to, string $message): ?string;
}
