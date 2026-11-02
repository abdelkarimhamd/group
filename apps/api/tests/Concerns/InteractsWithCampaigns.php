<?php

namespace Tests\Concerns;

use App\Modules\Campaign\Enums\ParticipationStatus;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Campaign\Services\Joins;
use App\Modules\Identity\Models\Address;
use App\Modules\Identity\Models\PrivacyConsent;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\RejectionReason;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Services\Holds;
use App\Support\Actor;
use App\Support\Audit\StatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait InteractsWithCampaigns
{
    use InteractsWithIdentity;

    /**
     * A customer who accepted the current privacy policy and saved an address.
     *
     * @return array{User, string, Address}
     */
    protected function customerReadyToJoin(): array
    {
        [$user, $token] = $this->customerWithToken();

        PrivacyConsent::create([
            'user_id' => $user->id,
            'policy_version' => config('groupbuy.account.privacy_policy_version'),
            'accepted_at' => now(),
        ]);

        $address = Address::factory()->for($user)->default()->create();

        return [$user, $token, $address];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function joinInput(Campaign $campaign, Address $address, array $overrides = []): array
    {
        return $overrides + [
            'client_request_id' => (string) Str::uuid(),
            'product_variant_id' => $campaign->variants()->firstOrFail()->uuid,
            'quantity' => 1,
            'shown_unit_price_halalas' => 12000,
            'delivery_address_id' => $address->uuid,
        ];
    }

    /**
     * Joins through the API and returns the participation.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function joinAs(string $token, Campaign $campaign, Address $address, array $overrides = []): CampaignParticipation
    {
        $id = $this->withToken($token)
            ->postJson("/api/v1/campaigns/{$campaign->uuid}/participations", $this->joinInput($campaign, $address, $overrides))
            ->assertCreated()
            ->json('data.id');
        $this->forgetAuthenticatedUsers();

        return CampaignParticipation::query()->where('uuid', $id)->sole();
    }

    /**
     * What payment confirmation (E9) does once Moyasar reports the hold, in
     * one transaction and in the lock order campaign, participation,
     * payment: record the authorization, then count the units. A join that
     * can no longer count gets its hold voided after commit.
     *
     * Payment statuses only move forward: the same hold reported again
     * changes nothing, and a hold that arrives after its payment row was
     * settled (the join ended) is recorded on a row of its own.
     */
    protected function authorizeHold(CampaignParticipation $participation): bool
    {
        return DB::transaction(function () use ($participation): bool {
            Campaign::query()->whereKey($participation->campaign_id)->lockForUpdate()->firstOrFail();
            $participation = CampaignParticipation::query()->whereKey($participation->id)->lockForUpdate()->firstOrFail();
            $payment = PaymentAuthorization::query()->where('participation_id', $participation->id)->latest('id')->lockForUpdate()->firstOrFail();

            if ($payment->status === PaymentStatus::Authorized) {
                return $participation->status === ParticipationStatus::Authorized;
            }

            if ($payment->status !== PaymentStatus::PendingAuthorization) {
                $payment = PaymentAuthorization::create([
                    'participation_id' => $participation->id,
                    'authorized_amount_halalas' => $participation->hold_amount_halalas,
                    'currency' => 'SAR',
                    'status' => PaymentStatus::PendingAuthorization,
                    'expires_at' => now()->addDays((int) config('groupbuy.payments.hold_lifetime_days')),
                ]);
                app(StatusHistory::class)->record($payment, null, PaymentStatus::PendingAuthorization, Actor::system(), 'late_hold');
            }

            $payment->forceFill([
                'status' => PaymentStatus::Authorized,
                'provider_payment_id' => 'fake_authorized_'.$payment->uuid,
                'source_type' => 'creditcard',
                'scheme' => 'mada',
                'card_last4' => '0000',
                'authorized_at' => now(),
                'provider_status' => 'authorized',
            ])->save();
            app(StatusHistory::class)->record($payment, PaymentStatus::PendingAuthorization, PaymentStatus::Authorized, Actor::system());

            $counted = app(Joins::class)->commitAuthorized($participation, Actor::system());

            if (! $counted) {
                $reason = match ($participation->refresh()->status) {
                    ParticipationStatus::ReservationExpired, ParticipationStatus::PaymentFailed => RejectionReason::ReservationExpired,
                    ParticipationStatus::Cancelled => RejectionReason::ParticipationCancelled,
                    default => RejectionReason::CampaignClosed,
                };
                app(Holds::class)->release($payment, $reason, Actor::system());
            }

            return $counted;
        });
    }
}
