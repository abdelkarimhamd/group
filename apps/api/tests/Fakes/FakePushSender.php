<?php

namespace Tests\Fakes;

use App\Modules\Notifications\Push\PushDeliveryFailed;
use App\Modules\Notifications\Push\PushSender;

/**
 * Records push messages instead of sending them. Set $failing to simulate a provider outage.
 */
final class FakePushSender implements PushSender
{
    /** @var list<array{tokens: list<string>, title: string, body: string, data: array<string, string>}> */
    public array $sent = [];

    public bool $failing = false;

    public function send(array $tokens, string $title, string $body, array $data = []): ?string
    {
        if ($this->failing) {
            throw new PushDeliveryFailed('Firebase unavailable (fake).');
        }

        $this->sent[] = ['tokens' => $tokens, 'title' => $title, 'body' => $body, 'data' => $data];

        return 'fake-push-'.count($this->sent);
    }
}
