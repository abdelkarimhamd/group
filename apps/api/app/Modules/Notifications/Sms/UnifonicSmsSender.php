<?php

namespace App\Modules\Notifications\Sms;

use Illuminate\Support\Facades\Http;
use LogicException;
use Throwable;

/**
 * Unifonic's SMS API (POST /rest/SMS/messages, form-encoded, AppSid in the
 * body). Our message reference goes out as the CorrelationID, so a retry of
 * a message Unifonic already took is refused as a duplicate (409 or ER-09),
 * which counts as sent, and its delivery receipt can be matched to ours.
 * Numbers go out as 9665XXXXXXXX (international, no plus).
 */
final class UnifonicSmsSender implements SmsSender
{
    public function __construct(
        private readonly string $appSid,
        private readonly string $senderId,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {
        if ($appSid === '') {
            throw new LogicException('Set UNIFONIC_APP_SID to send SMS through Unifonic.');
        }
    }

    public function send(string $to, string $message, ?string $reference = null): ?string
    {
        $form = array_filter([
            'AppSid' => $this->appSid,
            'SenderID' => $this->senderId,
            'Recipient' => ltrim($to, '+'),
            'Body' => $message,
            'responseType' => 'JSON',
            'CorrelationID' => $reference === null ? null : self::correlationId($reference),
        ], fn (?string $value) => $value !== null && $value !== '');

        try {
            $response = Http::asForm()->acceptJson()->timeout($this->timeoutSeconds)
                ->post(rtrim($this->baseUrl, '/').'/rest/SMS/messages', $form);
        } catch (Throwable $e) {
            throw new SmsDeliveryFailed('Unifonic could not be reached: '.$e->getMessage(), previous: $e);
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $code = is_string($body['errorCode'] ?? null) ? $body['errorCode'] : null;

        if ($response->successful() && ($body['success'] ?? null) === true) {
            return isset($data['MessageID']) && is_scalar($data['MessageID']) ? (string) $data['MessageID'] : null;
        }

        // The same CorrelationID again: Unifonic already has this message.
        if ($response->status() === 409 || $code === 'ER-09') {
            return null;
        }

        $why = is_string($body['message'] ?? null) && $body['message'] !== '' ? $body['message'] : 'no reason given';

        throw new SmsDeliveryFailed("Unifonic refused the message ({$response->status()} ".($code ?? '-')."): {$why}");
    }

    /**
     * Letters and digits only, at most 36: the format Unifonic accepts is not
     * documented, so keep to the safest one. Our references end in a UUID,
     * so a long one keeps its last 32 characters: the UUID's hex digits.
     */
    private static function correlationId(string $reference): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9]/', '', $reference);

        return strlen($clean) <= 36 ? $clean : substr($clean, -32);
    }
}
