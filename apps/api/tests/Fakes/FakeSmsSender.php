<?php

namespace Tests\Fakes;

use App\Modules\Notifications\Sms\SmsDeliveryFailed;
use App\Modules\Notifications\Sms\SmsSender;
use PHPUnit\Framework\Assert;

/**
 * Records texts instead of sending them. Set $failing to simulate a provider outage.
 */
final class FakeSmsSender implements SmsSender
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public bool $failing = false;

    public function send(string $to, string $message): void
    {
        if ($this->failing) {
            throw new SmsDeliveryFailed('Provider unavailable (fake).');
        }

        $this->sent[] = ['to' => $to, 'message' => $message];
    }

    /**
     * The 6-digit code in the most recent text.
     */
    public function lastCode(): string
    {
        Assert::assertNotEmpty($this->sent, 'No SMS was sent.');
        Assert::assertSame(1, preg_match('/\b(\d{6})\b/', end($this->sent)['message'], $matches), 'The last SMS has no 6-digit code.');

        return $matches[1];
    }
}
