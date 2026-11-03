<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationStatus;
use App\Modules\Notifications\Jobs\SendNotification;
use App\Modules\Notifications\Models\DeviceToken;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Push\PushDeliveryFailed;
use App\Modules\Notifications\Push\PushSender;
use App\Modules\Notifications\Sms\SmsDeliveryFailed;
use App\Modules\Notifications\Sms\SmsOutcomeUnknown;
use App\Modules\Notifications\Sms\SmsSender;
use App\Support\Phone\SaudiMobile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Sends queued messages. Each try is recorded and committed before the
 * provider is called, never inside a transaction, and its result after.
 * A failed try is due again after its wait (`retry_after_minutes`); after
 * the last one the message is marked failed. A try that never reports
 * back (the worker died) is due again after `stuck_after_minutes`, so a
 * message can rarely arrive twice but is never silently dropped. Every
 * send and every failure is logged.
 */
final class Deliveries
{
    public function __construct(
        private readonly Templates $templates,
        private readonly SmsSender $sms,
        private readonly PushSender $push,
    ) {}

    public function deliver(int $notificationId): void
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()) {
            throw new LogicException('Send notifications outside any transaction.');
        }

        $notification = DB::transaction(function () use ($notificationId): ?Notification {
            $notification = Notification::query()->with('user')->lockForUpdate()->find($notificationId);
            $now = CarbonImmutable::now();

            if ($notification === null
                || $notification->status !== NotificationStatus::Queued
                || $notification->next_attempt_at?->greaterThan($now)) {
                return null;
            }

            // Tries that never reported back (a crash mid-send) count too.
            if ($notification->attempts >= (int) config('groupbuy.notifications.max_attempts')) {
                $notification->status = NotificationStatus::Failed;
                $notification->next_attempt_at = null;
                $notification->last_error = 'no_result_after_max_attempts';
                $notification->save();
                Log::warning('notifications.failed', ['notification_id' => $notification->id, 'error' => 'no_result_after_max_attempts']);

                return null;
            }

            $notification->attempts++;
            $notification->last_attempt_at = $now;
            $notification->next_attempt_at = $now->addMinutes((int) config('groupbuy.notifications.stuck_after_minutes'));
            $notification->save();

            return $notification;
        });

        if ($notification === null) {
            return;
        }

        $context = [
            'notification_id' => $notification->id,
            'channel' => $notification->channel->value,
            'template' => $notification->template_key->value,
            'attempt' => $notification->attempts,
        ];

        try {
            $messageId = $this->send($notification);
        } catch (SmsOutcomeUnknown $e) {
            // It may have arrived, and the provider cannot tell us: sending
            // it again could deliver it twice, so it is closed, not retried.
            $error = mb_substr('outcome_unknown: '.$e->getMessage(), 0, 500);
            $this->finish($notification, NotificationStatus::Failed, null, $error);
            Log::warning('notifications.outcome_unknown', $context + ['error' => $error]);

            return;
        } catch (SmsDeliveryFailed|PushDeliveryFailed $e) {
            $this->failed($notification, mb_substr($e->getMessage(), 0, 500), $context);

            return;
        }

        if ($messageId === false) {
            // Nobody to send it to any more (phone removed, app signed out).
            $this->finish($notification, NotificationStatus::Failed, null, 'no_recipient');
            Log::info('notifications.skipped', $context);

            return;
        }

        $this->finish($notification, NotificationStatus::Sent, $messageId, null);
        Log::info('notifications.sent', $context + ['provider_message_id' => $messageId]);
    }

    /**
     * Dispatch every message that is due: retries whose wait is over, and
     * messages whose job was lost. Returns how many were dispatched.
     */
    public function dispatchDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $lost = $now->subMinutes((int) config('groupbuy.notifications.stuck_after_minutes'));
        $ids = Notification::query()
            ->where('status', NotificationStatus::Queued)
            ->where(fn ($query) => $query
                ->where('next_attempt_at', '<=', $now)
                ->orWhere(fn ($fresh) => $fresh->whereNull('next_attempt_at')->where('created_at', '<=', $lost)))
            ->orderBy('id')
            ->limit(1000)
            ->pluck('id');

        foreach ($ids as $id) {
            SendNotification::dispatch($id);
        }

        return $ids->count();
    }

    /**
     * @return string|false|null the provider's message ID, or false when there is no one to send to
     */
    private function send(Notification $notification): string|false|null
    {
        $user = $notification->user;
        $text = $this->templates->render($notification->template_key, $notification->locale, $notification->payload);

        if ($notification->channel === NotificationChannel::Sms) {
            if ($user->phone_e164 === null) {
                return false;
            }

            Log::info('notifications.sending', ['notification_id' => $notification->id, 'to' => SaudiMobile::mask($user->phone_e164)]);

            return $this->sms->send($user->phone_e164, $text['body'], "notification:{$notification->uuid}");
        }

        if ($notification->channel === NotificationChannel::Push) {
            $tokens = DeviceToken::query()->where('user_id', $user->id)->orderByDesc('last_seen_at')->pluck('fcm_token')->all();

            if ($tokens === []) {
                return false;
            }

            return $this->push->send($tokens, $text['title'], $text['body'], [
                'notification_id' => $notification->uuid,
                'template' => $notification->template_key->value,
            ]);
        }

        throw new LogicException("Nothing sends {$notification->channel->value} messages yet.");
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function failed(Notification $notification, string $error, array $context): void
    {
        if ($notification->attempts >= (int) config('groupbuy.notifications.max_attempts')) {
            $this->finish($notification, NotificationStatus::Failed, null, $error);
            Log::warning('notifications.failed', $context + ['error' => $error]);

            return;
        }

        $waits = array_values((array) config('groupbuy.notifications.retry_after_minutes'));
        $wait = (int) ($waits[$notification->attempts - 1] ?? end($waits));
        $now = CarbonImmutable::now();

        Notification::query()->whereKey($notification->id)->where('status', NotificationStatus::Queued)->update([
            'last_error' => $error,
            'next_attempt_at' => $now->addMinutes($wait),
            'updated_at' => $now,
        ]);
        Log::warning('notifications.retrying', $context + ['error' => $error, 'retry_in_minutes' => $wait]);
    }

    private function finish(Notification $notification, NotificationStatus $status, ?string $messageId, ?string $error): void
    {
        $now = CarbonImmutable::now();

        Notification::query()->whereKey($notification->id)->where('status', NotificationStatus::Queued)->update([
            'status' => $status,
            'provider_message_id' => $messageId,
            'last_error' => $error,
            'next_attempt_at' => null,
            'sent_at' => $status === NotificationStatus::Sent ? $now : null,
            'updated_at' => $now,
        ]);
    }
}
