<?php

namespace App\Modules\Notifications\Push;

use App\Modules\Notifications\Models\DeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Expo's push service (POST https://exp.host/--/api/v2/push/send), which
 * delivers through Firebase on Android and APNs on iOS with the
 * credentials uploaded to EAS. The app registers the Expo push token from
 * getExpoPushTokenAsync(); other tokens are skipped. Expo answers with one
 * ticket per message: a token Expo says is no longer registered is removed,
 * and the send counts as delivered when any device took it.
 */
final class ExpoPushSender implements PushSender
{
    /** Messages per request (Expo's limit). */
    private const BATCH = 100;

    public function __construct(
        private readonly string $url,
        private readonly string $accessToken,
        private readonly int $timeoutSeconds,
    ) {}

    public function send(array $tokens, string $title, string $body, array $data = []): ?string
    {
        $tokens = array_values(array_filter($tokens, fn (string $token) => preg_match('/^Expo(nent)?PushToken\[[^\]]+\]$/', $token) === 1));

        if ($tokens === []) {
            throw new PushDeliveryFailed('None of the devices has an Expo push token.');
        }

        $ids = [];
        $errors = [];

        foreach (array_chunk($tokens, self::BATCH) as $batch) {
            $messages = array_map(fn (string $token) => [
                'to' => $token,
                'title' => $title,
                'body' => $body,
                'data' => (object) $data,
                'sound' => 'default',
                'priority' => 'high',
                'channelId' => 'default',
            ], $batch);

            foreach ($this->post($messages) as $i => $ticket) {
                $ticket = is_array($ticket) ? $ticket : [];

                if (($ticket['status'] ?? null) === 'ok') {
                    $ids[] = is_string($ticket['id'] ?? null) ? $ticket['id'] : null;

                    continue;
                }

                $error = is_array($ticket['details'] ?? null) && is_string($ticket['details']['error'] ?? null) ? $ticket['details']['error'] : 'unknown';
                $errors[] = $error;

                if ($error === 'DeviceNotRegistered' && isset($batch[$i])) {
                    // The app was removed or the token replaced: stop sending to it.
                    DeviceToken::query()->where('token_hash', hash('sha256', $batch[$i]))->delete();
                    Log::info('notifications.push.token_removed', ['reason' => $error]);
                }
            }
        }

        if ($ids === []) {
            throw new PushDeliveryFailed('Expo refused the message for every device: '.implode(', ', array_unique($errors)));
        }

        return array_values(array_filter($ids))[0] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<mixed> the tickets, in the order of the messages
     */
    private function post(array $messages): array
    {
        $request = Http::acceptJson()->asJson()->timeout($this->timeoutSeconds)
            ->withHeaders(['Accept-Encoding' => 'gzip, deflate']);

        if ($this->accessToken !== '') {
            $request = $request->withToken($this->accessToken);
        }

        try {
            $response = $request->post($this->url, $messages);
        } catch (Throwable $e) {
            throw new PushDeliveryFailed('Expo could not be reached: '.$e->getMessage(), previous: $e);
        }

        $json = $response->json();
        $tickets = is_array($json) && is_array($json['data'] ?? null) ? array_values($json['data']) : null;

        if (! $response->successful() || $tickets === null) {
            $why = is_array($json) && is_array($json['errors'] ?? null) ? json_encode($json['errors'], JSON_UNESCAPED_UNICODE) : '';

            throw new PushDeliveryFailed("Expo answered {$response->status()} {$why}");
        }

        return $tickets;
    }
}
