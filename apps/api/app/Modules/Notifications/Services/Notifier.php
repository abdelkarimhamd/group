<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationTemplate;
use App\Modules\Notifications\Jobs\SendNotification;
use App\Modules\Notifications\Models\DeviceToken;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Queues a message to a customer (spec, E14). Call it in the transaction
 * that makes the news: the rows commit with the change (or not at all), and
 * each is sent by its own job after commit. The dedupe key makes a re-run
 * of the same step send nothing new.
 *
 * Every message goes by SMS when the customer has a phone number, and by
 * push when they have the mobile app. The text is chosen at send time, in
 * the customer's language.
 */
final class Notifier
{
    /**
     * @param  array<string, mixed>  $payload  see Templates for the conventions
     * @param  string  $dedupeKey  unique per message, such as "charged:customer_order:42"; the channel is appended
     * @return int how many messages were queued (0 when all were queued before)
     */
    public function send(User $user, NotificationTemplate $template, Model $subject, array $payload, string $dedupeKey): int
    {
        $channels = [];

        if ($user->phone_e164 !== null) {
            $channels[] = NotificationChannel::Sms;
        }

        if (DeviceToken::query()->where('user_id', $user->id)->exists()) {
            $channels[] = NotificationChannel::Push;
        }

        $queued = 0;
        $now = CarbonImmutable::now();

        foreach ($channels as $channel) {
            $row = DB::selectOne(
                'INSERT INTO notifications (uuid, user_id, channel, template_key, locale, payload, subject_type, subject_id, dedupe_key, status, attempts, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?::jsonb, ?, ?, ?, ?, 0, ?, ?)
                 ON CONFLICT (dedupe_key) DO NOTHING
                 RETURNING id',
                [
                    (string) Str::uuid7(),
                    $user->id,
                    $channel->value,
                    $template->value,
                    $user->locale,
                    json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    $subject->getMorphClass(),
                    $subject->getKey(),
                    "{$dedupeKey}:{$channel->value}",
                    'queued',
                    $now,
                    $now,
                ],
            );

            if ($row !== null) {
                SendNotification::dispatch((int) $row->id)->afterCommit();
                $queued++;
            }
        }

        return $queued;
    }
}
