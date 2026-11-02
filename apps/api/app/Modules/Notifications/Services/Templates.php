<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Enums\NotificationTemplate;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Turns a message's template and payload into text in the customer's
 * language, at send time. Payload conventions: a value with `ar` and `en`
 * keys is a name in both languages; a key ending in `_halalas` is an amount
 * in SAR; a key ending in `_at` is a time, shown in Riyadh time. The
 * placeholder drops the suffix: `hold_halalas` fills `:hold`.
 */
final class Templates
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{title: string, body: string}
     */
    public function render(NotificationTemplate $template, string $locale, array $payload): array
    {
        $params = [];

        foreach ($payload as $key => $value) {
            [$name, $text] = match (true) {
                is_array($value) => [$key, (string) ($value[$locale] ?? $value['en'] ?? '')],
                str_ends_with($key, '_halalas') => [substr($key, 0, -8), Money::sar((int) $value)->decimal()],
                str_ends_with($key, '_at') => [substr($key, 0, -3), $this->time((string) $value, $locale)],
                default => [$key, (string) $value],
            };
            $params[$name] = $text;
        }

        return [
            'title' => __("notifications.{$template->value}.title", $params, $locale),
            'body' => __("notifications.{$template->value}.body", $params, $locale),
        ];
    }

    private function time(string $value, string $locale): string
    {
        return CarbonImmutable::parse($value)
            ->setTimezone((string) config('groupbuy.display_timezone'))
            ->locale($locale)
            ->translatedFormat('j F, H:i');
    }
}
