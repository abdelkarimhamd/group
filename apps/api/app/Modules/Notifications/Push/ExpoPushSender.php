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
     * Sends one request. Expo refuses a request whose tokens belong to more
     * than one Expo project (PUSH_TOO_MANY_EXPERIENCE_IDS, for example after
     * the app moved to a new EAS project): it lists the tokens of each
     * project, and each project's messages are then sent on their own.
     *
     * @param  list<array<string, mixed>>  $messages
     * @return list<mixed> the tickets, in the order of the messages
     */
    private function post(array $messages, bool $split = true): array
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

        if ($response->successful() && $tickets !== null) {
            return $tickets;
        }

        $errors = is_array($json) && is_array($json['errors'] ?? null) ? array_values(array_filter($json['errors'], 'is_array')) : [];
        $groups = $split ? $this->byProject($errors, $messages) : null;

        if ($groups !== null) {
            return $this->postEach($messages, $groups);
        }

        throw new PushDeliveryFailed(trim("Expo answered {$response->status()} ".$this->describe($errors)));
    }

    /**
     * The messages' positions grouped by Expo project, or null when Expo did
     * not ask for that or the groups would not split the request.
     *
     * @param  list<array<mixed>>  $errors
     * @param  list<array<string, mixed>>  $messages
     * @return list<list<int>>|null
     */
    private function byProject(array $errors, array $messages): ?array
    {
        foreach ($errors as $error) {
            if (($error['code'] ?? null) !== 'PUSH_TOO_MANY_EXPERIENCE_IDS' || ! is_array($error['details'] ?? null)) {
                continue;
            }

            $project = [];
            foreach (array_values($error['details']) as $n => $tokens) {
                foreach (is_array($tokens) ? $tokens : [] as $token) {
                    if (is_string($token)) {
                        $project[$token] ??= $n;
                    }
                }
            }

            $groups = [];
            foreach ($messages as $i => $message) {
                // Tokens Expo did not list go together in a request of their own.
                $groups[$project[$message['to']] ?? 'unlisted'][] = $i;
            }

            return count($groups) > 1 ? array_values($groups) : null;
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<list<int>>  $groups
     * @return list<mixed>
     */
    private function postEach(array $messages, array $groups): array
    {
        $tickets = [];

        foreach ($groups as $group) {
            try {
                $answer = $this->post(array_map(fn (int $i) => $messages[$i], $group), split: false);
            } catch (PushDeliveryFailed $e) {
                $answer = [];
                Log::warning('notifications.push.project_failed', ['error' => $e->getMessage()]);
            }

            foreach ($group as $n => $i) {
                $tickets[$i] = $answer[$n] ?? ['status' => 'error', 'details' => ['error' => 'ProjectRequestFailed']];
            }
        }

        ksort($tickets);

        return array_values($tickets);
    }

    /**
     * Expo's error codes and messages, never their details (which can list
     * every push token in the request), with any token in a message masked.
     *
     * @param  list<array<mixed>>  $errors
     */
    private function describe(array $errors): string
    {
        $parts = [];

        foreach ($errors as $error) {
            $code = is_string($error['code'] ?? null) ? $error['code'] : '';
            $message = is_string($error['message'] ?? null) ? mb_substr($error['message'], 0, 200) : '';
            $message = preg_replace('/Expo(nent)?PushToken\[[^\]]*\]/', '[token]', $message) ?? '';
            $parts[] = trim($code.($code !== '' && $message !== '' ? ': ' : '').$message);
        }

        return implode('; ', array_filter($parts));
    }
}
