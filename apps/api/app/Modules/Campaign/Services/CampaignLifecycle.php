<?php

namespace App\Modules\Campaign\Services;

use App\Exceptions\ApiException;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Enums\CloseReason;
use App\Modules\Campaign\Enums\ClosingStep;
use App\Modules\Campaign\Enums\DeliveryMode;
use App\Modules\Campaign\Enums\ParticipationStatus;
use App\Modules\Campaign\Events\CampaignClosingStarted;
use App\Modules\Campaign\Events\CampaignFailed;
use App\Modules\Campaign\Events\CampaignProgressChanged;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignPriceTier;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Enums\VariantStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Finance\Enums\ApprovalType;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Finance\Services\Approvals;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Notifications\Enums\NotificationTemplate;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\RejectionReason;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Services\Holds;
use App\Modules\Pricing\MarginCalculator;
use App\Modules\Pricing\TierSet;
use App\Modules\Supplier\Enums\QuotationStatus;
use App\Modules\Supplier\Enums\SupplierStatus;
use App\Modules\Supplier\Models\SupplierQuotation;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\Changes;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Campaign status changes (spec, Campaign lifecycle). Every change locks the
 * campaign row, re-checks the status under the lock, writes a campaign
 * event in the same transaction, and audits admin actions.
 *
 * draft -> pending_approval -> scheduled -> active -> closing, with
 * pending_approval -> draft on rejection and cancellation from any status
 * before a capture. Terms (tiers, target, maximum, deadline) lock at
 * approval; while active the only change is the single extension. The
 * closing job (App\Modules\Campaign\Services\Closing) takes closing on to
 * purchasing or failed through fail() and startPurchasing().
 */
class CampaignLifecycle
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StatusHistory $history,
        private readonly CampaignEvents $events,
        private readonly CampaignCodes $codes,
        private readonly MarginCalculator $margins,
        private readonly Holds $holds,
        private readonly Approvals $approvals,
        private readonly CampaignNotices $notices,
    ) {}

    /**
     * @param  array<string, mixed>  $input  Validated AdminCampaignInput.
     */
    public function create(array $input, AdminUser $by): Campaign
    {
        return DB::transaction(function () use ($input, $by): Campaign {
            $campaign = new Campaign;
            $variantIds = $this->fill($campaign, $input);
            $campaign->code = $this->codes->next();
            $campaign->created_by_admin_id = $by->id;
            $campaign->save();
            $campaign->variants()->sync($variantIds);

            $actor = Actor::admin($by->id);
            $this->events->record($campaign, CampaignEvents::CREATED, $actor, to: CampaignStatus::Draft);
            $this->audit->record($actor, 'campaign.create', $campaign, after: $this->terms($campaign, $variantIds));

            return $campaign;
        });
    }

    /**
     * Edit a draft. A stale lock_version is a 409.
     *
     * @param  array<string, mixed>  $input  Validated AdminCampaignInput plus lock_version.
     */
    public function update(string $uuid, array $input, AdminUser $by): Campaign
    {
        return DB::transaction(function () use ($uuid, $input, $by): Campaign {
            $campaign = $this->lockDraft($uuid, (int) $input['lock_version']);
            $variantsBefore = $campaign->variants()->pluck('product_variants.id')->all();

            $variantIds = $this->fill($campaign, $input);
            [$before, $after] = Changes::pending($campaign);

            if ($variantsBefore != $variantIds) {
                $before['product_variant_ids'] = $this->variantUuids($variantsBefore);
                $after['product_variant_ids'] = $this->variantUuids($variantIds);
            }

            if ($after !== []) {
                $campaign->lock_version++;
                $campaign->save();
                $campaign->variants()->sync($variantIds);
                $this->audit->record(Actor::admin($by->id), 'campaign.update', $campaign, $before, $after);
            }

            return $campaign;
        });
    }

    /**
     * Save a new tier set version. Only in draft; a reason is required from
     * version 2. Old versions are kept.
     *
     * @param  list<array{min_qty: int, max_qty: int, unit_price_halalas: int}>  $tiers
     */
    public function replaceTiers(string $uuid, int $lockVersion, array $tiers, ?string $reason, AdminUser $by): Campaign
    {
        return DB::transaction(function () use ($uuid, $lockVersion, $tiers, $reason, $by): Campaign {
            $campaign = $this->lockDraft($uuid, $lockVersion);

            if ($campaign->current_tier_version !== null && ($reason === null || trim($reason) === '')) {
                throw ValidationException::withMessages(['reason' => [__('validation.required', ['attribute' => 'reason'])]]);
            }

            $set = TierSet::fromArray($tiers);
            $set->assertValidFor($campaign->max_qty);

            $previous = $campaign->current_tier_version;
            $version = ($previous ?? 0) + 1;

            foreach ($set->toArray() as $tier) {
                CampaignPriceTier::create($tier + [
                    'campaign_id' => $campaign->id,
                    'version' => $version,
                    'currency' => 'SAR',
                    'created_by_admin_id' => $by->id,
                    'reason' => $reason,
                ]);
            }

            $before = $previous === null ? null : $campaign->tierSet()?->toArray();
            $campaign->current_tier_version = $version;
            $campaign->lock_version++;
            $campaign->save();
            $campaign->unsetRelation('currentTiers');

            $actor = Actor::admin($by->id);
            $this->events->record($campaign, CampaignEvents::TIERS_CHANGED, $actor, [
                'version' => $version, 'tiers' => $set->toArray(), 'reason' => $reason,
            ]);
            $this->audit->record($actor, 'campaign.tiers', $campaign,
                before: $before === null ? null : ['version' => $previous, 'tiers' => $before],
                after: ['version' => $version, 'tiers' => $set->toArray(), 'reason' => $reason],
            );

            return $campaign;
        });
    }

    /**
     * draft -> pending_approval, once the campaign is complete enough to judge.
     */
    public function submit(string $uuid, AdminUser $by): Campaign
    {
        return DB::transaction(function () use ($uuid, $by): Campaign {
            $campaign = $this->lock($uuid);
            $this->assertStatus($campaign, CampaignStatus::Draft);
            $this->assertReady($campaign);

            return $this->transition($campaign, CampaignStatus::PendingApproval, CampaignEvents::SUBMITTED, Actor::admin($by->id), 'campaign.submit');
        });
    }

    /**
     * pending_approval -> draft, with the reason the approver gave.
     */
    public function reject(string $uuid, string $reason, AdminUser $by): Campaign
    {
        return DB::transaction(function () use ($uuid, $reason, $by): Campaign {
            $campaign = $this->lock($uuid);
            $this->assertStatus($campaign, CampaignStatus::PendingApproval);

            return $this->transition($campaign, CampaignStatus::Draft, CampaignEvents::REJECTED, Actor::admin($by->id), 'campaign.reject', ['reason' => $reason]);
        });
    }

    /**
     * pending_approval -> scheduled. Checks the margin (an override needs
     * campaigns.override_margin and a reason), locks the terms, snapshots
     * the landed cost, moves a past start to now and sets the deadline.
     *
     * @throws ApiException 422 margin_below_floor (with the margin report), 403 forbidden for an override without permission
     */
    public function approve(string $uuid, ?string $overrideReason, AdminUser $by): Campaign
    {
        if ($overrideReason !== null && ! $by->can('campaigns.override_margin')) {
            throw new ApiException(403, 'forbidden');
        }

        return DB::transaction(function () use ($uuid, $overrideReason, $by): Campaign {
            $campaign = $this->lock($uuid);
            $this->assertStatus($campaign, CampaignStatus::PendingApproval);
            $this->assertReady($campaign);

            $report = $this->margins->report($campaign);

            if (! $report->passes() && $overrideReason === null) {
                throw new ApiException(422, 'margin_below_floor', extra: ['margin' => $report]);
            }

            $now = CarbonImmutable::now();
            $tiers = $campaign->tierSet();
            $campaign->landed_cost_per_unit_halalas = $this->margins->landedCostOf($campaign);
            $campaign->starts_at = $campaign->starts_at->max($now);
            $campaign->deadline_at = $campaign->starts_at->addDays((int) config('groupbuy.campaigns.duration_days'));
            $campaign->approved_by_admin_id = $by->id;
            $campaign->approved_at = $now;
            $campaign->margin_override_reason = $report->passes() ? null : $overrideReason;

            $actor = Actor::admin($by->id);
            $campaign = $this->transition($campaign, CampaignStatus::Scheduled, CampaignEvents::APPROVED, $actor, 'campaign.approve', [
                'starts_at' => $campaign->starts_at->toIso8601ZuluString(),
                'deadline_at' => $campaign->deadline_at->toIso8601ZuluString(),
                'tier_version' => $campaign->current_tier_version,
                'landed_cost_per_unit_halalas' => $campaign->landed_cost_per_unit_halalas,
                'margin' => $report->summary(),
                'margin_override_reason' => $campaign->margin_override_reason,
            ]);

            // The price history starts here: the price the first unit sells at.
            $first = $tiers->current(0);
            $this->events->record($campaign, CampaignEvents::PRICE_CHANGED, $actor, [
                'from_unit_price_halalas' => null,
                'to_unit_price_halalas' => $first->unitPriceHalalas,
                'tier' => ['min_qty' => $first->minQty, 'max_qty' => $first->maxQty],
                'tier_version' => $campaign->current_tier_version,
            ]);

            return $campaign;
        });
    }

    /**
     * scheduled -> active for campaigns whose start has come. Returns how many went live.
     */
    public function activateDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $ids = Campaign::query()->where('status', CampaignStatus::Scheduled)->where('starts_at', '<=', $now)->orderBy('id')->pluck('id');
        $activated = 0;

        foreach ($ids as $id) {
            $activated += DB::transaction(function () use ($id, $now): int {
                $campaign = Campaign::query()->whereKey($id)->lockForUpdate()->firstOrFail();

                if ($campaign->status !== CampaignStatus::Scheduled || $campaign->starts_at->greaterThan($now)) {
                    return 0;
                }

                $this->transition($campaign, CampaignStatus::Active, CampaignEvents::PUBLISHED, Actor::system());
                CampaignProgressChanged::dispatch($campaign->id);

                return 1;
            });
        }

        return $activated;
    }

    /**
     * active -> closing for campaigns past their deadline. Returns how many started closing.
     */
    public function closeDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $ids = Campaign::query()->where('status', CampaignStatus::Active)->where('deadline_at', '<=', $now)->orderBy('id')->pluck('id');
        $closed = 0;

        foreach ($ids as $id) {
            $closed += DB::transaction(function () use ($id, $now): int {
                $campaign = Campaign::query()->whereKey($id)->lockForUpdate()->firstOrFail();

                if ($campaign->status !== CampaignStatus::Active || $campaign->deadline_at->greaterThan($now)) {
                    return 0;
                }

                $this->enterClosing($campaign, Actor::system());

                return 1;
            });
        }

        return $closed;
    }

    /**
     * active -> closing: joins stop and the closing job takes over at
     * settling_joins. The deadline and the maximum share this transition,
     * so only one wins. Call with the campaign locked.
     */
    public function enterClosing(Campaign $campaign, Actor $actor): void
    {
        if ($campaign->status !== CampaignStatus::Active) {
            throw new LogicException("Campaign {$campaign->id} is {$campaign->status->value}; only an active campaign starts closing.");
        }

        $reason = $campaign->committed_qty >= $campaign->max_qty ? CloseReason::MaxReached : CloseReason::Deadline;
        $campaign->close_reason = $reason;
        $campaign->closing_step = ClosingStep::SettlingJoins;
        $campaign->closed_at = CarbonImmutable::now();

        $this->transition($campaign, CampaignStatus::Closing, CampaignEvents::CLOSED, $actor, payload: ['close_reason' => $reason->value]);
        CampaignClosingStarted::dispatch($campaign->id);
        CampaignProgressChanged::dispatch($campaign->id);
    }

    /**
     * Whether the deadline can be extended now, and to when at the latest
     * (spec, Single admin extension): once, while active and before the deal
     * is confirmed, to a date at least 3 days before the earliest hold
     * expires and never more than 14 days out less those 3 days.
     *
     * Holds still waiting for authorization count too: they were created at
     * most a reservation ago and may still authorize.
     *
     * @return array{allowed: bool, blocked_reason: string|null, latest_allowed_deadline_at: CarbonImmutable|null, earliest_hold_expires_at: CarbonImmutable|null}
     */
    public function extensionWindow(Campaign $campaign, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $blocked = fn (string $reason, ?CarbonImmutable $earliest = null) => [
            'allowed' => false, 'blocked_reason' => $reason, 'latest_allowed_deadline_at' => null, 'earliest_hold_expires_at' => $earliest,
        ];

        if ($campaign->status !== CampaignStatus::Active) {
            return $blocked('not_active');
        }

        if (! $now->lessThan($campaign->deadline_at)) {
            return $blocked('deadline_passed');
        }

        if ($campaign->extended_at !== null) {
            return $blocked('already_extended');
        }

        if ($campaign->deal_confirmed_at !== null) {
            return $blocked('deal_confirmed');
        }

        $earliest = PaymentAuthorization::query()
            ->whereIn('participation_id', $campaign->participations()->select('id'))
            ->whereIn('status', [PaymentStatus::Authorized, PaymentStatus::PendingAuthorization])
            ->min('expires_at');
        $earliest = $earliest === null ? null : CarbonImmutable::parse($earliest);

        $bound = $now->addDays((int) config('groupbuy.payments.hold_lifetime_days'));
        $latest = ($earliest === null ? $bound : $earliest->min($bound))
            ->subDays((int) config('groupbuy.campaigns.extension_buffer_days'));

        if (! $latest->greaterThan($campaign->deadline_at)) {
            return $blocked('no_room_before_hold_expiry', $earliest);
        }

        return ['allowed' => true, 'blocked_reason' => null, 'latest_allowed_deadline_at' => $latest, 'earliest_hold_expires_at' => $earliest];
    }

    /**
     * Use the one extension. Every guard is checked under the campaign row lock.
     *
     * @throws ApiException 422 extension_not_allowed, with the window
     */
    public function extend(string $uuid, CarbonImmutable $newDeadline, string $reason, AdminUser $by): Campaign
    {
        return DB::transaction(function () use ($uuid, $newDeadline, $reason, $by): Campaign {
            $campaign = $this->lock($uuid);
            $window = $this->extensionWindow($campaign);

            if (! $window['allowed'] || ! $newDeadline->greaterThan($campaign->deadline_at) || $newDeadline->greaterThan($window['latest_allowed_deadline_at'])) {
                throw new ApiException(422, 'extension_not_allowed', 'new_deadline_at', extra: ['window' => self::windowJson($window)]);
            }

            $old = $campaign->deadline_at;
            $campaign->original_deadline_at = $old;
            $campaign->deadline_at = $newDeadline;
            $campaign->extended_at = CarbonImmutable::now();
            $campaign->extended_by_admin_id = $by->id;
            $campaign->extension_reason = $reason;
            $campaign->lock_version++;
            $campaign->save();

            $actor = Actor::admin($by->id);
            $change = ['deadline_at' => $newDeadline->toIso8601ZuluString(), 'reason' => $reason];
            $this->events->record($campaign, CampaignEvents::EXTENDED, $actor, ['from_deadline_at' => $old->toIso8601ZuluString()] + $change);
            $this->audit->record($actor, 'campaign.extend', $campaign, ['deadline_at' => $old->toIso8601ZuluString()], $change);
            $this->notices->extended($campaign);
            CampaignProgressChanged::dispatch($campaign->id);

            return $campaign;
        });
    }

    /**
     * Cancel a campaign. Before any capture it ends at once: every join is
     * released and every hold voided (after commit). Once captures have
     * started (closing at capturing or needs_admin, or purchasing), money
     * may have to go back, so the cancellation waits for a second admin with
     * the finance role: this opens (or finds) that approval request and
     * changes nothing else. Fulfilling and ended campaigns cannot be cancelled.
     *
     * @throws ApiException 409 invalid_transition
     */
    public function cancel(string $uuid, string $reason, AdminUser $by): Campaign|ApprovalRequest
    {
        return DB::transaction(function () use ($uuid, $reason, $by): Campaign|ApprovalRequest {
            $campaign = $this->lock($uuid);
            $step = $campaign->closing_step;

            if (($campaign->status === CampaignStatus::Closing && in_array($step, [ClosingStep::Capturing, ClosingStep::NeedsAdmin], true))
                || $campaign->status === CampaignStatus::Purchasing) {
                return $this->requestCancellation($campaign, $reason, $by, 'cancel');
            }

            $allowed = in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::PendingApproval, CampaignStatus::Scheduled, CampaignStatus::Active], true)
                || ($campaign->status === CampaignStatus::Closing && in_array($step, [ClosingStep::SettlingJoins, ClosingStep::AwaitingSupplier], true));

            if (! $allowed) {
                throw new ApiException(409, 'invalid_transition');
            }

            $actor = Actor::admin($by->id);
            $released = $this->releaseParticipations($campaign, $actor, 'campaign_cancelled');
            $campaign->end_reason = $reason;

            $campaign = $this->transition($campaign, CampaignStatus::Cancelled, CampaignEvents::CANCELLED, $actor, 'campaign.cancel', [
                'reason' => $reason, 'released_participations' => $released,
            ]);
            CampaignProgressChanged::dispatch($campaign->id);

            return $campaign;
        });
    }

    /**
     * Ask a finance admin to approve cancelling a campaign whose holds may
     * already be captured. Once approved, CampaignCancellations carries it
     * out (refunding captured joins, cancelling orders and the purchase
     * order). Asking again returns the request already waiting, or the one
     * already approved and not yet carried out. Call with the campaign locked.
     */
    public function requestCancellation(Campaign $campaign, string $reason, AdminUser $by, string $source): ApprovalRequest
    {
        if (($approved = CampaignCancellations::waitingRequest($campaign)) !== null) {
            return $approved;
        }

        $captured = $campaign->participations()->where('status', ParticipationStatus::Captured);
        $request = $this->approvals->request(ApprovalType::CampaignCancellation, $campaign, [
            'reason' => $reason,
            'source' => $source,
            'campaign_status' => $campaign->status->value,
            'closing_step' => $campaign->closing_step?->value,
            'captured_participations' => (clone $captured)->count(),
            'captured_amount_halalas' => (int) (clone $captured)->sum('capture_amount_halalas'),
        ], $by);

        if ($request->wasRecentlyCreated) {
            $this->events->record($campaign, CampaignEvents::CANCELLATION_REQUESTED, Actor::admin($by->id), [
                'approval_request_id' => $request->uuid, 'reason' => $reason, 'source' => $source,
            ]);
        }

        return $request;
    }

    /**
     * Carry out a cancellation a finance admin approved, in the caller's
     * transaction with the campaign locked: joins still holding a card are
     * released (holds voided after commit) and the campaign is cancelled.
     * Refunding captured joins and closing their orders is the caller's
     * (App\Modules\Campaign\Services\CampaignCancellations).
     *
     * @param  array<string, mixed>  $payload
     */
    public function cancelApproved(Campaign $campaign, ApprovalRequest $request, Actor $actor, array $payload = []): Campaign
    {
        $released = $this->releaseParticipations($campaign, $actor, 'campaign_cancelled');
        $reason = (string) ($request->payload['reason'] ?? 'cancelled');
        $campaign->end_reason = $reason;

        $campaign = $this->transition($campaign, CampaignStatus::Cancelled, CampaignEvents::CANCELLED, $actor, 'campaign.cancel', [
            'reason' => $reason,
            'approval_request_id' => $request->uuid,
            'released_participations' => $released,
        ] + $payload);
        CampaignProgressChanged::dispatch($campaign->id);

        return $campaign;
    }

    /**
     * closing -> failed (spec, Failure path): in the caller's transaction,
     * with the campaign locked, every join still holding units is released
     * and its hold voided after commit. Only before any capture.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fail(Campaign $campaign, string $endReason, Actor $actor, array $payload = []): Campaign
    {
        if ($campaign->status !== CampaignStatus::Closing || ! in_array($campaign->closing_step, [ClosingStep::SettlingJoins, ClosingStep::AwaitingSupplier, ClosingStep::Capturing], true)) {
            throw new LogicException("Campaign {$campaign->id} cannot fail from {$campaign->status->value}/{$campaign->closing_step?->value}.");
        }

        $released = $this->releaseParticipations($campaign, $actor, 'campaign_failed');
        $campaign->end_reason = $endReason;

        $campaign = $this->transition($campaign, CampaignStatus::Failed, CampaignEvents::FAILED, $actor, payload: [
            'end_reason' => $endReason, 'released_participations' => $released,
        ] + $payload);
        CampaignFailed::dispatch($campaign->id);
        CampaignProgressChanged::dispatch($campaign->id);

        return $campaign;
    }

    /**
     * closing -> purchasing once captures are done (closing step 7), with
     * closing_step notifying. Call with the campaign locked.
     *
     * @param  array<string, mixed>  $payload
     */
    public function startPurchasing(Campaign $campaign, Actor $actor, array $payload = []): Campaign
    {
        if ($campaign->status !== CampaignStatus::Closing || ! in_array($campaign->closing_step, [ClosingStep::Capturing, ClosingStep::NeedsAdmin], true)) {
            throw new LogicException("Campaign {$campaign->id} cannot start purchasing from {$campaign->status->value}/{$campaign->closing_step?->value}.");
        }

        $campaign->closing_step = ClosingStep::Notifying;

        // The closing job creates the supplier purchase order (E12) in this transaction.
        $campaign = $this->transition($campaign, CampaignStatus::Purchasing, CampaignEvents::CAPTURES_COMPLETED, $actor, payload: [
            'captured_qty' => $campaign->captured_qty,
            'final_unit_price_halalas' => $campaign->final_unit_price_halalas,
        ] + $payload);
        CampaignProgressChanged::dispatch($campaign->id);

        return $campaign;
    }

    /**
     * The supplier's goods are on their way (the purchase order is marked
     * shipped): purchasing moves to fulfilling. Call with the campaign locked.
     *
     * @param  array<string, mixed>  $payload
     */
    public function startFulfilling(Campaign $campaign, Actor $actor, array $payload = []): Campaign
    {
        if ($campaign->status !== CampaignStatus::Purchasing) {
            throw new LogicException("Campaign {$campaign->id} cannot start fulfilling from {$campaign->status->value}.");
        }

        $campaign = $this->transition($campaign, CampaignStatus::Fulfilling, CampaignEvents::SUPPLIER_SHIPPED, $actor, payload: $payload);
        CampaignProgressChanged::dispatch($campaign->id);

        return $campaign;
    }

    /**
     * Every customer order is delivered or cancelled: fulfilling moves to
     * completed. Call with the campaign locked.
     */
    public function complete(Campaign $campaign, Actor $actor): Campaign
    {
        if ($campaign->status !== CampaignStatus::Fulfilling) {
            throw new LogicException("Campaign {$campaign->id} cannot complete from {$campaign->status->value}.");
        }

        $campaign = $this->transition($campaign, CampaignStatus::Completed, CampaignEvents::COMPLETED, $actor);
        CampaignProgressChanged::dispatch($campaign->id);

        return $campaign;
    }

    /**
     * Ends every join that still holds units: participations waiting for
     * payment or authorized become `released`, their holds are released
     * (voided after commit) and their reserved units freed. Committed units
     * stay as the record of what was sold. Call with the campaign locked.
     * Returns how many were released.
     */
    private function releaseParticipations(Campaign $campaign, Actor $actor, string $reason): int
    {
        $released = 0;
        $reservedUnits = 0;

        foreach ($campaign->participations()->whereIn('status', [ParticipationStatus::PendingPayment, ParticipationStatus::Authorized])->orderBy('id')->lockForUpdate()->get() as $participation) {
            $from = $participation->status;
            $reservedUnits += $from === ParticipationStatus::PendingPayment ? $participation->qty : 0;
            $participation->status = ParticipationStatus::Released;
            $participation->cancel_reason = $reason;
            $participation->save();
            $this->history->record($participation, $from, ParticipationStatus::Released, $actor, $reason);

            foreach ($participation->payments()->whereIn('status', [PaymentStatus::PendingAuthorization, PaymentStatus::Authorized])->get() as $payment) {
                $this->holds->release($payment, RejectionReason::CampaignClosed, $actor);
            }

            // Only paid joins had a hold to tell the customer about.
            if ($from === ParticipationStatus::Authorized) {
                $this->notices->holdReleased($reason === 'campaign_cancelled' ? NotificationTemplate::CampaignCancelled : NotificationTemplate::CampaignFailed, $campaign, $participation);
            }

            $released++;
        }

        if ($reservedUnits > 0) {
            $freed = DB::update(
                'UPDATE campaigns SET reserved_qty = reserved_qty - ? WHERE id = ? AND reserved_qty >= ?',
                [$reservedUnits, $campaign->id, $reservedUnits],
            );

            if ($freed !== 1) {
                throw new LogicException("Campaign {$campaign->id} has fewer reserved units than its waiting joins hold.");
            }

            $campaign->reserved_qty -= $reservedUnits;
            $campaign->syncOriginalAttribute('reserved_qty');
        }

        return $released;
    }

    /**
     * The `ExtensionWindow` schema.
     *
     * @param  array{allowed: bool, blocked_reason: string|null, latest_allowed_deadline_at: CarbonImmutable|null, earliest_hold_expires_at: CarbonImmutable|null}  $window
     * @return array<string, mixed>
     */
    public static function windowJson(array $window): array
    {
        return [
            'allowed' => $window['allowed'],
            'blocked_reason' => $window['blocked_reason'],
            'latest_allowed_deadline_at' => $window['latest_allowed_deadline_at']?->toIso8601ZuluString(),
            'earliest_hold_expires_at' => $window['earliest_hold_expires_at']?->toIso8601ZuluString(),
        ];
    }

    /**
     * Saves the new status with its campaign event (and audit, for admin
     * actions) in the caller's transaction.
     *
     * @param  array<string, mixed>  $payload
     */
    private function transition(Campaign $campaign, CampaignStatus $to, string $event, Actor $actor, ?string $auditAction = null, array $payload = []): Campaign
    {
        $from = $campaign->status;
        $campaign->status = $to;

        if ($auditAction !== null) {
            $campaign->lock_version++;
        }

        $campaign->save();
        $this->events->record($campaign, $event, $actor, $payload, $from, $to);

        if ($auditAction !== null) {
            $this->audit->record($actor, $auditAction, $campaign, ['status' => $from->value], ['status' => $to->value] + $payload);
        }

        return $campaign;
    }

    /**
     * Checks a campaign can go to (or be approved from) review: valid tiers,
     * a recorded landed cost, an approved supplier, a usable quote and an
     * active product whose chosen variants are all active.
     */
    private function assertReady(Campaign $campaign): void
    {
        $campaign->loadMissing(['currentTiers', 'quotation.supplier', 'product']);
        $tiers = $campaign->tierSet() ?? throw ApiException::unprocessable('tiers_missing', 'tiers');
        $tiers->assertValidFor($campaign->max_qty);

        if ($this->margins->landedCostOf($campaign) === null) {
            throw ApiException::unprocessable('landed_cost_missing', 'supplier_quotation_id');
        }

        if ($campaign->quotation->supplier->status !== SupplierStatus::Approved) {
            throw ApiException::unprocessable('supplier_not_approved', 'supplier_quotation_id');
        }

        if (in_array($campaign->quotation->status, [QuotationStatus::Rejected, QuotationStatus::Expired], true)) {
            throw ApiException::unprocessable('quotation_not_usable', 'supplier_quotation_id');
        }

        if ($campaign->product->status !== ProductStatus::Active) {
            throw ApiException::unprocessable('product_not_active', 'product_id');
        }

        if ($campaign->variants()->where('product_variants.status', '!=', VariantStatus::Active)->exists()) {
            throw ApiException::unprocessable('variant_not_active', 'product_variant_ids');
        }
    }

    /**
     * Copies validated input onto a draft and checks it against the
     * catalogue. Returns the variant IDs to sync.
     *
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private function fill(Campaign $campaign, array $input): array
    {
        $product = Product::query()->where('uuid', $input['product_id'])->firstOrFail();
        $quotation = SupplierQuotation::query()->with('supplier')->where('uuid', $input['supplier_quotation_id'])->firstOrFail();

        if ($quotation->product_id !== $product->id) {
            throw ApiException::unprocessable('quotation_not_for_product', 'supplier_quotation_id');
        }

        if (in_array($quotation->status, [QuotationStatus::Rejected, QuotationStatus::Expired], true)) {
            throw ApiException::unprocessable('quotation_not_usable', 'supplier_quotation_id');
        }

        if ($quotation->supplier->status === SupplierStatus::Blocked) {
            throw ApiException::unprocessable('supplier_blocked', 'supplier_quotation_id');
        }

        $variantIds = ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereIn('uuid', $input['product_variant_ids'])
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if (count($variantIds) !== count(array_unique($input['product_variant_ids']))) {
            throw ApiException::unprocessable('variant_not_in_product', 'product_variant_ids');
        }

        if (ProductVariant::query()->whereIn('id', $variantIds)->where('status', '!=', VariantStatus::Active)->exists()) {
            throw ApiException::unprocessable('variant_not_active', 'product_variant_ids');
        }

        // A quote for one variant cannot price the others.
        if ($quotation->product_variant_id !== null && $variantIds !== [$quotation->product_variant_id]) {
            throw ApiException::unprocessable('quotation_not_for_variants', 'product_variant_ids');
        }

        $campaign->fill([
            'title_ar' => $input['title_ar'],
            'title_en' => $input['title_en'],
            'min_target_qty' => (int) $input['min_target_qty'],
            'max_qty' => (int) $input['max_qty'],
            'per_customer_max_qty' => isset($input['per_customer_max_qty']) ? (int) $input['per_customer_max_qty'] : null,
            'starts_at' => CarbonImmutable::parse($input['starts_at'])->utc(),
            'delivery_mode' => $input['delivery_mode'] ?? DeliveryMode::Warehouse->value,
        ]);
        $campaign->product_id = $product->id;
        $campaign->supplier_quotation_id = $quotation->id;

        return $variantIds;
    }

    private function lock(string $uuid): Campaign
    {
        return Campaign::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
    }

    private function lockDraft(string $uuid, int $lockVersion): Campaign
    {
        $campaign = $this->lock($uuid);
        $this->assertStatus($campaign, CampaignStatus::Draft);

        if ($campaign->lock_version !== $lockVersion) {
            throw new ApiException(409, 'stale_campaign');
        }

        return $campaign;
    }

    private function assertStatus(Campaign $campaign, CampaignStatus $expected): void
    {
        if ($campaign->status !== $expected) {
            throw new ApiException(409, 'invalid_transition');
        }
    }

    /**
     * @param  list<int>  $variantIds
     * @return array<string, mixed>
     */
    private function terms(Campaign $campaign, array $variantIds): array
    {
        return [
            'code' => $campaign->code,
            'product_id' => $campaign->product_id,
            'supplier_quotation_id' => $campaign->supplier_quotation_id,
            'title_ar' => $campaign->title_ar,
            'title_en' => $campaign->title_en,
            'product_variant_ids' => $this->variantUuids($variantIds),
            'min_target_qty' => $campaign->min_target_qty,
            'max_qty' => $campaign->max_qty,
            'per_customer_max_qty' => $campaign->per_customer_max_qty,
            'starts_at' => $campaign->starts_at->toIso8601ZuluString(),
            'delivery_mode' => $campaign->delivery_mode->value,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function variantUuids(array $ids): array
    {
        return ProductVariant::query()->whereIn('id', $ids)->orderBy('id')->pluck('uuid')->all();
    }
}
