<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Jobs\ProcessMoyasarWebhook;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receiving Moyasar webhooks. Moyasar puts the secret token set in its
 * dashboard into every event body (open question Q5 asks to confirm this is
 * the only check it offers). Every event is stored; one that fails the check
 * is kept but never processed, and a verified event is processed once per
 * event ID. Processing never trusts the body: the job fetches the payment
 * from Moyasar.
 */
class MoyasarWebhooks
{
    /**
     * Store the event and queue it. Returns false when verification failed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function receive(array $payload, string $rawBody): bool
    {
        $valid = $this->verify($payload);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $eventId = is_string($payload['id'] ?? null) && $payload['id'] !== '' && strlen($payload['id']) <= 100
            ? $payload['id']
            : 'body:'.hash('sha256', $rawBody);

        // The secret is never stored.
        if (array_key_exists('secret_token', $payload)) {
            $payload['secret_token'] = '[redacted]';
        }

        try {
            // In its own transaction, so a resend never poisons one the caller has open.
            $event = DB::transaction(fn () => PaymentWebhookEvent::create([
                'provider' => 'moyasar',
                'provider_event_id' => $eventId,
                'event_type' => is_string($payload['type'] ?? null) ? substr($payload['type'], 0, 64) : null,
                'provider_payment_id' => is_string($data['id'] ?? null) && strlen($data['id']) <= 100 ? $data['id'] : null,
                'signature_valid' => $valid,
                'payload' => $payload,
                'received_at' => CarbonImmutable::now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // A verified event we already have: Moyasar sent it again.
            return true;
        }

        if (! $valid) {
            Log::warning('payments.webhook.unverified', ['event' => $eventId]);

            return false;
        }

        ProcessMoyasarWebhook::dispatch($event->id);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function verify(array $payload): bool
    {
        $secret = (string) config('services.moyasar.webhook_secret');
        $token = $payload['secret_token'] ?? null;

        return $secret !== '' && is_string($token) && hash_equals($secret, $token);
    }
}
