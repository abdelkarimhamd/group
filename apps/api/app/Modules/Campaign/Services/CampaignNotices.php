<?php

namespace App\Modules\Campaign\Services;

use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Enums\ParticipationStatus;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Enums\NotificationTemplate;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Payments\Models\PaymentAuthorization;

/**
 * What customers are told about their joins (spec, E14). Each call queues
 * the message in the caller's transaction; the dedupe keys make a re-run of
 * the same step tell nobody twice. News about a join goes to its owner, one
 * message per join; news about the campaign goes once to everyone holding
 * a paid join.
 */
final class CampaignNotices
{
    public function __construct(private readonly Notifier $notifier) {}

    public function joined(Campaign $campaign, CampaignParticipation $participation): void
    {
        $closing = $campaign->status === CampaignStatus::Closing;

        $this->aboutJoin($closing ? NotificationTemplate::JoinedClosing : NotificationTemplate::Joined, $campaign, $participation, [
            'qty' => $participation->qty,
        ] + ($closing ? [] : ['deadline_at' => $campaign->deadline_at?->toIso8601ZuluString()]), NotificationTemplate::Joined);
    }

    /**
     * The hold of a paid join was released: the campaign failed or was
     * cancelled, the customer cancelled, or the capture failed.
     */
    public function holdReleased(NotificationTemplate $template, Campaign $campaign, CampaignParticipation $participation): void
    {
        $this->aboutJoin($template, $campaign, $participation);
    }

    /**
     * A hold that came through after the join stopped counting was voided.
     * One message per hold.
     */
    public function holdVoided(Campaign $campaign, CampaignParticipation $participation, PaymentAuthorization $payment): void
    {
        $user = User::query()->findOrFail($participation->user_id);

        $this->notifier->send($user, NotificationTemplate::HoldVoided, $payment, [
            'campaign' => self::title($campaign),
            'hold_halalas' => $payment->authorized_amount_halalas,
        ], "hold_voided:payment_authorization:{$payment->id}");
    }

    public function dealConfirmed(Campaign $campaign): void
    {
        if ($campaign->status === CampaignStatus::Closing) {
            $this->toEveryoneHolding(NotificationTemplate::DealConfirmedClosing, $campaign, [], NotificationTemplate::DealConfirmed);

            return;
        }

        $this->toEveryoneHolding(NotificationTemplate::DealConfirmed, $campaign, [
            'deadline_at' => $campaign->deadline_at?->toIso8601ZuluString(),
        ]);
    }

    public function extended(Campaign $campaign): void
    {
        $this->toEveryoneHolding(NotificationTemplate::Extended, $campaign, [
            'deadline_at' => $campaign->deadline_at?->toIso8601ZuluString(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function aboutJoin(NotificationTemplate $template, Campaign $campaign, CampaignParticipation $participation, array $payload = [], ?NotificationTemplate $dedupeAs = null): void
    {
        $user = User::query()->findOrFail($participation->user_id);

        $this->notifier->send($user, $template, $participation, [
            'campaign' => self::title($campaign),
            'hold_halalas' => $participation->hold_amount_halalas,
        ] + $payload, ($dedupeAs ?? $template)->value.":campaign_participation:{$participation->id}");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toEveryoneHolding(NotificationTemplate $template, Campaign $campaign, array $payload, ?NotificationTemplate $dedupeAs = null): void
    {
        $users = User::query()
            ->whereIn('id', CampaignParticipation::query()
                ->select('user_id')
                ->where('campaign_id', $campaign->id)
                ->where('status', ParticipationStatus::Authorized))
            ->orderBy('id')
            ->get();

        foreach ($users as $user) {
            $this->notifier->send($user, $template, $campaign, ['campaign' => self::title($campaign)] + $payload, ($dedupeAs ?? $template)->value.":campaign:{$campaign->id}:user:{$user->id}");
        }
    }

    /**
     * @return array{ar: string, en: string}
     */
    public static function title(Campaign $campaign): array
    {
        return ['ar' => $campaign->title_ar, 'en' => $campaign->title_en];
    }
}
