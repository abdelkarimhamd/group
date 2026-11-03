<?php

namespace Tests\Fakes;

use App\Modules\Notifications\Sms\SmsDeliveryFailed;
use App\Modules\Notifications\Sms\SmsOutcomeUnknown;
use App\Modules\Notifications\Sms\SmsSender;
use PHPUnit\Framework\Assert;

/**
 * Records texts instead of sending them. Set $failing to simulate a provider
 * outage, $answerLost for a text that arrived but whose answer never came back.
 */
final class FakeSmsSender implements SmsSender
{
    /** @var list<array{to: string, message: string, reference: string|null}> */
    public array $sent = [];

    public bool $failing = false;

    public bool $answerLost = false;

    public function send(string $to, string $message, ?string $reference = null): ?string
    {
        if ($this->failing) {
            throw new SmsDeliveryFailed('Provider unavailable (fake).');
        }

        $this->sent[] = ['to' => $to, 'message' => $message, 'reference' => $reference];

        if ($this->answerLost) {
            throw new SmsOutcomeUnknown('Provider did not answer (fake).');
        }

        return 'fake-sms-'.count($this->sent);
    }

    /**
     * @return list<string> the texts sent to one number, oldest first
     */
    public function to(string $phone): array
    {
        return array_values(array_map(fn (array $sms) => $sms['message'], array_filter($this->sent, fn (array $sms) => $sms['to'] === $phone)));
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
