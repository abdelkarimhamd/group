<?php

namespace App\Modules\Campaign\Services;

use App\Exceptions\ApiException;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Enums\ClosingStep;
use App\Modules\Campaign\Enums\ParticipationStatus;
use App\Modules\Campaign\Events\CampaignDealConfirmed;
use App\Modules\Campaign\Events\CampaignProgressChanged;
use App\Modules\Campaign\Jobs\CloseCampaign;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Campaign\Models\CampaignPriceTier;
use App\Modules\Catalog\Enums\VariantStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\RejectionReason;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Services\Holds;
use App\Modules\Pricing\TierSet;
use App\Support\Actor;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Joining a campaign, and the units a join holds (spec: "How joins never
 * exceed max_qty").
 *
 * The campaign row is the only counter. A join reserves units with one
 * conditional UPDATE; under PostgreSQL READ COMMITTED a waiting UPDATE
 * re-checks its WHERE clause against the newest row, so with 10 units left
 * and 200 concurrent joins at most 10 units are reserved and the rest fail
 * at once. The row lock lasts until commit, which also serializes the
 * per-customer check. Units count (committed_qty) only once the payment is
 * authorized: commitAuthorized() moves them from reserved to committed.
 *
 * Lock order everywhere: campaign, then participation, then payment.
 */
class Joins
{
    /** Reservations expired per scheduler run; the provider check covers the same ones. */
    public const EXPIRY_BATCH = 500;

    public function __construct(
        private readonly StatusHistory $history,
        private readonly CampaignEvents $events,
        private readonly CampaignLifecycle $lifecycle,
        private readonly Holds $holds,
    ) {}

    /**
     * Reserve units and fix the hold price, creating the participation
     * (pending_payment) and its payment (pending_authorization). The server
     * computes every amount; the shown price is only compared.
     *
     * Retrying with the same client_request_id returns the original
     * participation instead of joining twice.
     *
     * @param  array{client_request_id: string, product_variant_id: string, quantity: int, shown_unit_price_halalas: int, delivery_address_id: string}  $input
     *
     * @throws ApiException 403 privacy_consent_required; 409 price_changed, sold_out or campaign_not_joinable;
     *                      422 per_customer_limit_exceeded, variant_not_in_campaign or address_not_found
     */
    public function join(User $user, Campaign $campaign, array $input): CampaignParticipation
    {
        if (($earlier = $this->earlier($user, $campaign, $input['client_request_id'])) !== null) {
            return $earlier;
        }

        if (! $user->hasAcceptedCurrentPrivacyPolicy()) {
            throw new ApiException(403, 'privacy_consent_required');
        }

        // A variant archived after approval is no longer sold.
        $variantId = $campaign->variants()
            ->where('product_variants.uuid', $input['product_variant_id'])
            ->where('product_variants.status', VariantStatus::Active)
            ->value('product_variants.id')
            ?? throw ApiException::unprocessable('variant_not_in_campaign', 'product_variant_id');

        $address = $user->addresses()->where('uuid', $input['delivery_address_id'])->first()
            ?? throw ApiException::unprocessable('address_not_found', 'delivery_address_id');

        $snapshot = $address->only([
            'recipient_name', 'recipient_phone', 'city', 'district', 'street', 'building_no',
            'postal_code', 'additional_no', 'short_address', 'lat', 'lng',
        ]) + ['id' => $address->uuid];

        try {
            $participation = DB::transaction(function () use ($user, $campaign, $variantId, $snapshot, $address, $input): CampaignParticipation {
                // Copies of one join sent at once wait here for each other, so
                // a retry finds the first instead of failing the unit or
                // per-customer checks against the first one's reservation.
                DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['join:'.$user->id.':'.$input['client_request_id']]);

                return $this->earlier($user, $campaign, $input['client_request_id'])
                    ?? $this->reserve($user, $campaign, (int) $variantId, $snapshot, $address->id, $input);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same join retried at the same moment: answer with the one that won.
            return $this->earlier($user, $campaign, $input['client_request_id']) ?? throw $e;
        }

        return $participation;
    }

    /**
     * Count an authorized join: move its units from reserved to committed,
     * set "Deal confirmed" the first time the target is reached, and start
     * closing when the maximum is reached. Runs inside the caller's
     * transaction.
     *
     * Returns true when the join counts, now or already (the same hold
     * confirmed twice changes nothing). Returns false when the join can no
     * longer count, because the participation ended or the campaign is past
     * settling its in-flight joins. Any units it still reserved are released
     * then (which voids an authorized hold after commit); for a join that had
     * already ended, the caller must release the hold itself.
     *
     * Locks the campaign, then the participation. A caller that also locks
     * the payment must lock these two first (lock order), and should mark
     * the payment authorized before calling, so a release here voids it.
     */
    public function commitAuthorized(CampaignParticipation $participation, Actor $actor): bool
    {
        $this->assertInTransaction();

        $campaign = Campaign::query()->whereKey($participation->campaign_id)->lockForUpdate()->firstOrFail();
        $participation = CampaignParticipation::query()->whereKey($participation->id)->lockForUpdate()->firstOrFail();

        if ($participation->status === ParticipationStatus::Authorized) {
            return true;
        }

        if ($participation->status !== ParticipationStatus::PendingPayment) {
            return false;
        }

        $settling = $campaign->status === CampaignStatus::Closing && $campaign->closing_step === ClosingStep::SettlingJoins;

        if ($campaign->status !== CampaignStatus::Active && ! $settling) {
            $this->releaseUnits($campaign, $participation, ParticipationStatus::Released, $actor, 'campaign_closed');

            return false;
        }

        $tiers = $this->tierSet($campaign->id, (int) $campaign->current_tier_version);
        $priceBefore = $tiers->current($campaign->committed_qty);
        $now = CarbonImmutable::now();

        $moved = DB::update(
            'UPDATE campaigns SET reserved_qty = reserved_qty - ?, committed_qty = committed_qty + ?, updated_at = ? WHERE id = ? AND reserved_qty >= ?',
            [$participation->qty, $participation->qty, $now, $campaign->id, $participation->qty],
        );

        if ($moved !== 1) {
            throw new LogicException("Campaign {$campaign->id} has fewer reserved units than participation {$participation->id} holds.");
        }

        $campaign->refresh();

        $participation->status = ParticipationStatus::Authorized;
        $participation->authorized_at = $now;
        $participation->save();
        $this->history->record($participation, ParticipationStatus::PendingPayment, ParticipationStatus::Authorized, $actor);

        $this->events->record($campaign, CampaignEvents::JOINED, $actor, [
            'participation_id' => $participation->uuid,
            'quantity' => $participation->qty,
            'hold_unit_price_halalas' => $participation->hold_unit_price_halalas,
        ]);

        if ($campaign->deal_confirmed_at === null && $campaign->committed_qty >= $campaign->min_target_qty) {
            $campaign->deal_confirmed_at = $now;
            $campaign->save();
            $this->events->record($campaign, CampaignEvents::DEAL_CONFIRMED, $actor, ['min_target_qty' => $campaign->min_target_qty]);
            CampaignDealConfirmed::dispatch($campaign->id);
        }

        $priceAfter = $tiers->current($campaign->committed_qty);

        // Recorded whenever the current tier changes, even to one at the same
        // price, so the event log shows every tier the campaign reached.
        if ($priceAfter->minQty !== $priceBefore->minQty) {
            $this->events->record($campaign, CampaignEvents::PRICE_CHANGED, $actor, [
                'from_unit_price_halalas' => $priceBefore->unitPriceHalalas,
                'to_unit_price_halalas' => $priceAfter->unitPriceHalalas,
                'tier' => ['min_qty' => $priceAfter->minQty, 'max_qty' => $priceAfter->maxQty],
                'tier_version' => $campaign->current_tier_version,
            ]);
        }

        // A late payment counted while closing settles its joins can fill the
        // campaign too, but closing has already started for the deadline.
        if ($campaign->status === CampaignStatus::Active && $campaign->committed_qty === $campaign->max_qty) {
            $this->events->record($campaign, CampaignEvents::MAX_REACHED, $actor, ['max_qty' => $campaign->max_qty]);
            $this->lifecycle->enterClosing($campaign, $actor);
        }

        $this->wakeClosingIfSettled($campaign);
        CampaignProgressChanged::dispatch($campaign->id);

        return true;
    }

    /**
     * The customer cancels their join (proposed rule, Q16): only while the
     * campaign is active and the deal is not yet confirmed. Reserved or
     * committed units go back on sale, the hold is released (voided after
     * commit), and a tier the campaign falls back below is recorded.
     *
     * @throws ApiException 409 cancellation_not_allowed
     */
    public function cancelByCustomer(User $user, string $uuid): CampaignParticipation
    {
        return DB::transaction(function () use ($user, $uuid): CampaignParticipation {
            $campaignId = CampaignParticipation::query()->where('uuid', $uuid)->where('user_id', $user->id)->firstOrFail()->campaign_id;
            $campaign = Campaign::query()->whereKey($campaignId)->lockForUpdate()->firstOrFail();
            $participation = CampaignParticipation::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            $cancellable = in_array($participation->status, [ParticipationStatus::PendingPayment, ParticipationStatus::Authorized], true)
                && $campaign->isJoinable()
                && $campaign->deal_confirmed_at === null;

            if (! $cancellable) {
                throw new ApiException(409, 'cancellation_not_allowed');
            }

            $actor = Actor::user($user->id);

            if ($participation->status === ParticipationStatus::PendingPayment) {
                $this->releaseUnits($campaign, $participation, ParticipationStatus::Cancelled, $actor, 'customer_cancelled');
                $campaign->refresh();
                $this->events->record($campaign, CampaignEvents::JOIN_CANCELLED, $actor, ['participation_id' => $participation->uuid, 'quantity' => $participation->qty, 'was' => 'pending_payment']);

                return $participation;
            }

            $tiers = $this->tierSet($campaign->id, (int) $campaign->current_tier_version);
            $priceBefore = $tiers->current($campaign->committed_qty);

            $freed = DB::update(
                'UPDATE campaigns SET committed_qty = committed_qty - ?, updated_at = ? WHERE id = ? AND committed_qty >= ?',
                [$participation->qty, CarbonImmutable::now(), $campaign->id, $participation->qty],
            );

            if ($freed !== 1) {
                throw new LogicException("Campaign {$campaign->id} has fewer committed units than participation {$participation->id} holds.");
            }

            $campaign->refresh();
            $participation->status = ParticipationStatus::Cancelled;
            $participation->cancel_reason = 'customer_cancelled';
            $participation->save();
            $this->history->record($participation, ParticipationStatus::Authorized, ParticipationStatus::Cancelled, $actor, 'customer_cancelled');

            foreach ($participation->payments()->whereIn('status', [PaymentStatus::PendingAuthorization, PaymentStatus::Authorized])->get() as $payment) {
                $this->holds->release($payment, RejectionReason::ParticipationCancelled, $actor);
            }

            $this->events->record($campaign, CampaignEvents::JOIN_CANCELLED, $actor, ['participation_id' => $participation->uuid, 'quantity' => $participation->qty, 'was' => 'authorized']);
            $priceAfter = $tiers->current($campaign->committed_qty);

            if ($priceAfter->minQty !== $priceBefore->minQty) {
                $this->events->record($campaign, CampaignEvents::PRICE_CHANGED, $actor, [
                    'from_unit_price_halalas' => $priceBefore->unitPriceHalalas,
                    'to_unit_price_halalas' => $priceAfter->unitPriceHalalas,
                    'tier' => ['min_qty' => $priceAfter->minQty, 'max_qty' => $priceAfter->maxQty],
                    'tier_version' => $campaign->current_tier_version,
                ]);
            }

            CampaignProgressChanged::dispatch($campaign->id);

            return $participation;
        });
    }

    /**
     * Reservations whose 15 minutes ran out without an authorized payment
     * free their units: `payment_failed` when the last card tried was
     * declined or its hold refused, `reservation_expired` otherwise. campaigns:tick asks the
     * provider about unsettled payments first, so a hold that went through
     * is counted rather than voided. Returns how many expired.
     */
    public function expireReservations(?CarbonImmutable $now = null, int $limit = self::EXPIRY_BATCH): int
    {
        $now ??= CarbonImmutable::now();
        $due = CampaignParticipation::query()
            ->where('status', ParticipationStatus::PendingPayment)
            ->where('reservation_expires_at', '<=', $now)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'campaign_id']);

        $expired = 0;

        foreach ($due as $row) {
            $expired += DB::transaction(function () use ($row, $now): int {
                $campaign = Campaign::query()->whereKey($row->campaign_id)->lockForUpdate()->firstOrFail();
                $participation = CampaignParticipation::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();

                if ($participation->status !== ParticipationStatus::PendingPayment || $participation->reservation_expires_at->greaterThan($now)) {
                    return 0;
                }

                // After a declined or refused card the customer was told; otherwise they never finished paying.
                $last = $participation->payments()->attempts()->latest('id')->first();
                $refused = $last !== null && ($last->status === PaymentStatus::Failed || $last->rejection_reason !== null);
                $this->releaseUnits($campaign, $participation, $refused ? ParticipationStatus::PaymentFailed : ParticipationStatus::ReservationExpired, Actor::system(), 'reservation_expired');

                return 1;
            });
        }

        return $expired;
    }

    /**
     * Ends a participation that still reserves units: frees them, records
     * the status, and releases its payment (voided after commit if it was
     * authorized meanwhile). Call with the campaign and participation locked.
     */
    private function releaseUnits(Campaign $campaign, CampaignParticipation $participation, ParticipationStatus $to, Actor $actor, string $reason): void
    {
        $freed = DB::update(
            'UPDATE campaigns SET reserved_qty = reserved_qty - ?, updated_at = ? WHERE id = ? AND reserved_qty >= ?',
            [$participation->qty, CarbonImmutable::now(), $campaign->id, $participation->qty],
        );

        if ($freed !== 1) {
            throw new LogicException("Campaign {$campaign->id} has fewer reserved units than participation {$participation->id} holds.");
        }

        $from = $participation->status;
        $participation->status = $to;
        $participation->cancel_reason = $reason;
        $participation->save();
        $this->history->record($participation, $from, $to, $actor, $reason);

        $rejection = match ($to) {
            ParticipationStatus::ReservationExpired, ParticipationStatus::PaymentFailed => RejectionReason::ReservationExpired,
            ParticipationStatus::Cancelled => RejectionReason::ParticipationCancelled,
            default => RejectionReason::CampaignClosed,
        };

        foreach ($participation->payments()->whereIn('status', [PaymentStatus::PendingAuthorization, PaymentStatus::Authorized])->get() as $payment) {
            $this->holds->release($payment, $rejection, $actor);
        }

        $this->wakeClosingIfSettled($campaign);
        CampaignProgressChanged::dispatch($campaign->id);
    }

    /**
     * The closing job waits at settling_joins until no join is in flight;
     * the join that settles last wakes it (after commit).
     */
    private function wakeClosingIfSettled(Campaign $campaign): void
    {
        if ($campaign->status !== CampaignStatus::Closing || $campaign->closing_step !== ClosingStep::SettlingJoins) {
            return;
        }

        if ((int) DB::table('campaigns')->where('id', $campaign->id)->value('reserved_qty') === 0) {
            CloseCampaign::dispatch($campaign->id)->afterCommit();
        }
    }

    private function reserve(User $user, Campaign $campaign, int $variantId, array $addressSnapshot, int $addressId, array $input): CampaignParticipation
    {
        $quantity = (int) $input['quantity'];
        $now = CarbonImmutable::now();

        // The only place units are reserved. Zero rows means sold out or closed.
        $row = DB::selectOne(<<<'SQL'
            UPDATE campaigns
               SET reserved_qty = reserved_qty + ?, updated_at = ?
             WHERE id = ?
               AND status = 'active'
               AND deadline_at > ?
               AND committed_qty + reserved_qty + ? <= max_qty
            RETURNING committed_qty, current_tier_version, per_customer_max_qty
            SQL, [$quantity, $now, $campaign->id, $now, $quantity]);

        if ($row === null) {
            $campaign->refresh();

            if (! $campaign->isJoinable($now)) {
                throw new ApiException(409, 'campaign_not_joinable');
            }

            throw new ApiException(409, 'sold_out', extra: ['remaining_qty' => $campaign->remainingQty()]);
        }

        $tier = $this->tierSet($campaign->id, (int) $row->current_tier_version)->current((int) $row->committed_qty);

        if ($tier->unitPriceHalalas !== (int) $input['shown_unit_price_halalas']) {
            throw new ApiException(409, 'price_changed', extra: ['current_unit_price' => $tier->unitPrice()]);
        }

        if ($row->per_customer_max_qty !== null) {
            $held = (int) CampaignParticipation::query()
                ->where('campaign_id', $campaign->id)
                ->where('user_id', $user->id)
                ->whereIn('status', ParticipationStatus::holdingUnits())
                ->sum('qty');

            if ($held + $quantity > (int) $row->per_customer_max_qty) {
                throw ApiException::unprocessable('per_customer_limit_exceeded', 'quantity');
            }
        }

        $holdAmount = $tier->unitPrice()->times($quantity);
        $actor = Actor::user($user->id);

        $participation = CampaignParticipation::create([
            'campaign_id' => $campaign->id,
            'user_id' => $user->id,
            'product_variant_id' => $variantId,
            'qty' => $quantity,
            'hold_unit_price_halalas' => $tier->unitPriceHalalas,
            'hold_amount_halalas' => $holdAmount->minor,
            'tier_version' => (int) $row->current_tier_version,
            'currency' => 'SAR',
            'delivery_address_id' => $addressId,
            'delivery_address' => $addressSnapshot,
            'status' => ParticipationStatus::PendingPayment,
            'reservation_expires_at' => $now->addMinutes((int) config('groupbuy.campaigns.reservation_minutes')),
            'client_request_id' => $input['client_request_id'],
        ]);
        $this->history->record($participation, null, ParticipationStatus::PendingPayment, $actor);

        $payment = PaymentAuthorization::create([
            'participation_id' => $participation->id,
            'authorized_amount_halalas' => $holdAmount->minor,
            'currency' => 'SAR',
            'status' => PaymentStatus::PendingAuthorization,
            'expires_at' => $now->addDays((int) config('groupbuy.payments.hold_lifetime_days')),
        ]);
        $this->history->record($payment, null, PaymentStatus::PendingAuthorization, $actor);

        CampaignProgressChanged::dispatch($campaign->id);

        return $participation;
    }

    /**
     * The participation this customer already made with this client request
     * ID, if any. Reusing an ID on another campaign is refused.
     */
    private function earlier(User $user, Campaign $campaign, string $clientRequestId): ?CampaignParticipation
    {
        $earlier = CampaignParticipation::query()
            ->where('user_id', $user->id)
            ->where('client_request_id', $clientRequestId)
            ->first();

        if ($earlier !== null && $earlier->campaign_id !== $campaign->id) {
            throw ValidationException::withMessages(['client_request_id' => [__('validation.unique', ['attribute' => 'client_request_id'])]]);
        }

        return $earlier;
    }

    private function tierSet(int $campaignId, int $version): TierSet
    {
        return TierSet::fromModels(CampaignPriceTier::query()
            ->where('campaign_id', $campaignId)
            ->where('version', $version)
            ->get());
    }

    private function assertInTransaction(): void
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isEmpty()) {
            throw new LogicException('Commit a join inside a database transaction.');
        }
    }
}
