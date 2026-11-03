<?php

namespace App\Modules\Notifications\Sms;

use App\Support\Http\Failures;
use Illuminate\Support\Facades\Http;
use LogicException;
use Throwable;

/**
 * Taqnyat's SMS API (POST /v1/messages, JSON, bearer token). The sender
 * name must already be active on the account. Taqnyat keeps no reference
 * of ours and has no way to look a message up, so a send that may have
 * reached it (a timeout after sending, a 5xx) is never sent again: it
 * throws SmsOutcomeUnknown. Only a send that certainly never left, or a
 * definite refusal, is a SmsDeliveryFailed to retry.
 */
final class TaqnyatSmsSender implements SmsSender
{
    public function __construct(
        private readonly string $token,
        private readonly string $sender,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {
        if ($token === '' || $sender === '') {
            throw new LogicException('Set TAQNYAT_TOKEN and SMS_SENDER to send SMS through Taqnyat.');
        }
    }

    public function send(string $to, string $message, ?string $reference = null): ?string
    {
        try {
            $response = Http::withToken($this->token)->acceptJson()->asJson()->timeout($this->timeoutSeconds)
                ->post(rtrim($this->baseUrl, '/').'/v1/messages', [
                    'recipients' => [(int) ltrim($to, '+')],
                    'body' => $message,
                    'sender' => $this->sender,
                ]);
        } catch (Throwable $e) {
            if (Failures::neverSent($e)) {
                throw new SmsDeliveryFailed('Taqnyat could not be reached: '.$e->getMessage(), previous: $e);
            }

            throw new SmsOutcomeUnknown('Taqnyat did not answer; the message may have arrived: '.$e->getMessage(), previous: $e);
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful() && isset($body['messageId']) && is_scalar($body['messageId'])) {
            return (string) $body['messageId'];
        }

        $why = is_string($body['message'] ?? null) && $body['message'] !== '' ? $body['message'] : 'no reason given';

        if ($response->serverError()) {
            throw new SmsOutcomeUnknown("Taqnyat failed ({$response->status()}); the message may have arrived: {$why}");
        }

        throw new SmsDeliveryFailed("Taqnyat refused the message ({$response->status()}): {$why}");
    }
}
