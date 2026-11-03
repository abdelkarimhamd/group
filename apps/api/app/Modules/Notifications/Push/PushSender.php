<?php

namespace App\Modules\Notifications\Push;

/**
 * Sends one push message to a customer's devices. Implementations throw
 * PushDeliveryFailed when the message could not be handed to the provider.
 */
interface PushSender
{
    /**
     * @param  list<string>  $tokens  Firebase registration tokens
     * @param  array<string, string>  $data  passed to the app with the message
     * @return string|null the provider's message ID
     *
     * @throws PushDeliveryFailed
     */
    public function send(array $tokens, string $title, string $body, array $data = []): ?string;
}
