<?php

namespace App\Modules\Campaign\Services;

use App\Exceptions\ApiException;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Enums\ClosingStep;
use App\Modules\Campaign\Enums\ParticipationStatus;
use App\Modules\Campaign\Events\CampaignAwaitingSupplier;
use App\Modules\Campaign\Events\CampaignNeedsAdmin;
use App\Modules\Campaign\Events\CampaignProgressChanged;
use App\Modules\Campaign\Events\CampaignSucceeded;
use App\Modules\Campaign\Events\ParticipationCaptureFailed;
use App\Modules\Campaign\Jobs\CloseCampaign;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\RejectionReason;
use App\Modules\Payments\Jobs\CapturePayment;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Services\Holds;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The closing job's steps (spec, Campaign lifecycle: "Closing job"). Each
 * run locks the campaign row, does the step that is due and saves progress
 * in closing_step, so a re-run, or two runs at once, resume safely:
 *
 *  settling_joins     waits until every in-flight join has authorized or
 *                     timed out (reserved_qty = 0); then writes, once, the
 *                     final quantity, tier and price, capture_by and each
 *                     join's capture amount, or fails the campaign below
 *                     its target;
 *  awaiting_supplier  waits for the admin to record the supplier's answer
 *                     (recordSupplierDecision); fails the campaign once
 *                     capture_by minus the lead time passes;
 *  capturing          queues one CapturePayment per authorized join; when
 *                     none is left, stores captured_qty and moves to
 *                     purchasing, or stops at needs_admin below the target
 *                     or the supplier's MOQ;
 *  needs_admin        waits for decideBelowTarget;
 *  notifying          (purchasing) tells the participants, then done.
 *
 * Nothing here calls the payment provider: captures and voids run in their
 * own jobs after commit. The job is woken by what it waits for (the last
 * settling join, the supplier decision, the last capture result) and by
 * campaigns:sweep-closing every five minutes.
 */
final class Closing
{
    /** More steps than one run can take; a guard against a loop. */
    private const MAX_STEPS = 10;

    public function __construct(
        private readonly CampaignLifecycle $lifecycle,
        private readonly CampaignEvents $events,
        private readonly StatusHistory $history,
        private readonly AuditLogger $audit,
        private readonly Holds $holds,
    ) {}

    /**
     * One run of the closing job for a campaign.
     */
    public function run(int $campaignId): void
    {
        for ($i = 0; $i < self::MAX_STEPS && $this->step($campaignId); $i++) {
            // Each step that returns true leaves the next one due.
        }
    }

    /**
     * Campaigns the sweep wakes: still closing, or purchasing but not yet
     * notified. Those waiting for an admin's below-target decision are left.
     *
     * @return list<int>
     */
    public function unfinished(): array
    {
        return Campaign::query()
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('status', CampaignStatus::Closing)->where('closing_step', '!=', ClosingStep::NeedsAdmin))
                ->orWhere(fn ($query) => $query->where('status', CampaignStatus::Purchasing)->where('closing_step', ClosingStep::Notifying)))
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * When the supplier's answer is due: capture_by minus the lead time.
     */
    public static function supplierDecisionDueAt(Campaign $campaign): ?CarbonImmutable
    {
        return $campaign->capture_by?->subHours((int) config('groupbuy.campaigns.supplier_decision_lead_hours'));
    }

    /**
     * Record the supplier's reconfirmation (closing step 4). `confirmed`
     * records the unit cost, stock and quotation reference in a
     * supplier_confirmed event and starts captures; `rejected` fails the
     * campaign and voids every hold.
     *
     * @param  array{decision: string, unit_cost_minor?: int|null, currency?: string|null, available_qty?: int|null, quotation_reference?: string|null, note?: string|null}  $input
     *
     * @throws ApiException 409 invalid_transition or supplier_decision_overdue; 422 supplier_stock_short
     */
    public function recordSupplierDecision(string $uuid, array $input, AdminUser $by): Campaign
    {
        return DB::transaction(function () use ($uuid, $input, $by): Campaign {
            $campaign = Campaign::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            if ($campaign->status !== CampaignStatus::Closing || $campaign->closing_step !== ClosingStep::AwaitingSupplier) {
                throw new ApiException(409, 'invalid_transition');
            }

            // Past the deadline the sweep fails the campaign: captures need the time left.
            if (! CarbonImmutable::now()->lessThan(self::supplierDecisionDueAt($campaign))) {
                throw new ApiException(409, 'supplier_decision_overdue');
            }

            $actor = Actor::admin($by->id);
            $note = $input['note'] ?? null;

            if ($input['decision'] === 'rejected') {
                $this->events->record($campaign, CampaignEvents::SUPPLIER_REJECTED, $actor, ['note' => $note]);
                $this->audit->record($actor, 'campaign.supplier_decision', $campaign,
                    ['closing_step' => ClosingStep::AwaitingSupplier->value],
                    ['decision' => 'rejected', 'note' => $note],
                );

                return $this->lifecycle->fail($campaign, 'supplier_rejected', $actor, ['note' => $note]);
            }

            // The proposed answer to the open question: a partial confirmation fails (record it as rejected).
            if ((int) $input['available_qty'] < $campaign->final_qty) {
                throw ApiException::unprocessable('supplier_stock_short', 'available_qty');
            }

            $campaign->loadMissing('quotation');
            $decision = [
                'unit_cost_minor' => (int) $input['unit_cost_minor'],
                'currency' => $input['currency'] ?? $campaign->quotation->currency,
                'available_qty' => (int) $input['available_qty'],
                'quotation_reference' => $input['quotation_reference'] ?? null,
                'note' => $note,
                'final_qty' => $campaign->final_qty,
            ];

            $campaign->closing_step = ClosingStep::Capturing;
            $campaign->save();
            $this->events->record($campaign, CampaignEvents::SUPPLIER_CONFIRMED, $actor, $decision);
            $this->audit->record($actor, 'campaign.supplier_decision', $campaign,
                ['closing_step' => ClosingStep::AwaitingSupplier->value],
                ['decision' => 'confirmed', 'closing_step' => ClosingStep::Capturing->value] + $decision,
            );
            CloseCampaign::dispatch($campaign->id)->afterCommit();

            return $campaign;
        });
    }

    /**
     * Captures fell short of the target or the supplier's MOQ (closing step
     * 6): `proceed` buys the captured units anyway (the supplier must accept
     * the lower quantity); `cancel` opens a finance approval to cancel and
     * return the money, and changes nothing else until it is approved.
     *
     * @throws ApiException 409 invalid_transition
     */
    public function decideBelowTarget(string $uuid, string $decision, string $reason, AdminUser $by): Campaign|ApprovalRequest
    {
        return DB::transaction(function () use ($uuid, $decision, $reason, $by): Campaign|ApprovalRequest {
            $campaign = Campaign::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            if ($campaign->status !== CampaignStatus::Closing || $campaign->closing_step !== ClosingStep::NeedsAdmin) {
                throw new ApiException(409, 'invalid_transition');
            }

            if ($decision === 'cancel') {
                return $this->lifecycle->requestCancellation($campaign, $reason, $by, 'below_target');
            }

            $actor = Actor::admin($by->id);
            $campaign->loadMissing('quotation');
            $context = [
                'decision' => 'proceed',
                'reason' => $reason,
                'captured_qty' => $campaign->captured_qty,
                'min_target_qty' => $campaign->min_target_qty,
                'supplier_moq' => $campaign->quotation->moq,
            ];

            $this->events->record($campaign, CampaignEvents::BELOW_TARGET_DECISION, $actor, $context);
            $this->audit->record($actor, 'campaign.below_target_decision', $campaign, ['closing_step' => ClosingStep::NeedsAdmin->value], $context);
            $campaign = $this->lifecycle->startPurchasing($campaign, $actor, ['decision' => 'proceed', 'reason' => $reason]);
            CloseCampaign::dispatch($campaign->id)->afterCommit();

            return $campaign;
        });
    }

    /**
     * A join's hold was captured (closing step 6). Call inside the
     * transaction that records the capture, with the campaign and the
     * participation locked. The campaign's last result wakes the job.
     */
    public function captured(CampaignParticipation $participation, Actor $actor): void
    {
        if ($participation->status !== ParticipationStatus::Authorized) {
            // Money was taken for a join that no longer counts: finance must look.
            Log::critical('campaigns.capture.participation_not_authorized', ['participation' => $participation->uuid, 'status' => $participation->status->value]);

            return;
        }

        $participation->status = ParticipationStatus::Captured;
        $participation->captured_at = CarbonImmutable::now();
        $participation->save();
        $this->history->record($participation, ParticipationStatus::Authorized, ParticipationStatus::Captured, $actor);

        $this->wakeIfCapturesDone($participation->campaign_id);
    }

    /**
     * A join's hold could not be captured (declined, or past its cutoff):
     * the join is left out of the order and its hold voided after commit.
     * Call inside the transaction that records it, with the campaign and
     * the participation locked.
     */
    public function captureFailed(CampaignParticipation $participation, ?PaymentAuthorization $hold, RejectionReason $reason, Actor $actor): void
    {
        $this->excludeFromCapture($participation, $hold, $reason, $actor);
        $this->wakeIfCapturesDone($participation->campaign_id);
    }

    /**
     * Runs the step that is due. Returns true when another step is due now.
     */
    private function step(int $campaignId): bool
    {
        return DB::transaction(function () use ($campaignId): bool {
            $campaign = Campaign::query()->whereKey($campaignId)->lockForUpdate()->first();

            if ($campaign === null) {
                return false;
            }

            $now = CarbonImmutable::now();

            return match (true) {
                $campaign->status === CampaignStatus::Active => $this->enterClosingIfDue($campaign, $now),
                $campaign->status === CampaignStatus::Purchasing && $campaign->closing_step === ClosingStep::Notifying => $this->notify($campaign),
                $campaign->status !== CampaignStatus::Closing => false,
                $campaign->closing_step === ClosingStep::SettlingJoins => $this->settle($campaign, $now),
                $campaign->closing_step === ClosingStep::AwaitingSupplier => $this->checkSupplierDeadline($campaign, $now),
                $campaign->closing_step === ClosingStep::Capturing => $this->checkCaptures($campaign),
                default => false,
            };
        });
    }

    /**
     * Step 1: an active campaign past its deadline, or full, starts closing.
     */
    private function enterClosingIfDue(Campaign $campaign, CarbonImmutable $now): bool
    {
        if ($campaign->deadline_at->greaterThan($now) && $campaign->committed_qty < $campaign->max_qty) {
            return false;
        }

        $this->lifecycle->enterClosing($campaign, Actor::system());

        return true;
    }

    /**
     * Steps 2 and 3: once no join is in flight, count the units whose hold
     * can still be captured and fix the final price, once.
     */
    private function settle(Campaign $campaign, CarbonImmutable $now): bool
    {
        // The last join to authorize or time out wakes the job (Joins).
        if ($campaign->reserved_qty > 0) {
            return false;
        }

        $actor = Actor::system();

        /** @var list<array{CampaignParticipation, PaymentAuthorization}> $counted */
        $counted = [];

        foreach ($campaign->participations()->where('status', ParticipationStatus::Authorized)->orderBy('id')->lockForUpdate()->get() as $participation) {
            $hold = $this->liveHold($participation);

            if ($hold !== null && $now->lessThan($hold->captureCutoffAt())) {
                $counted[] = [$participation, $hold];

                continue;
            }

            $this->excludeFromCapture($participation, $hold, RejectionReason::CaptureCutoffPassed, $actor);
        }

        $finalQty = array_sum(array_map(fn (array $pair): int => $pair[0]->qty, $counted));

        if ($finalQty < $campaign->min_target_qty) {
            $this->lifecycle->fail($campaign, 'target_missed', $actor, ['final_qty' => $finalQty, 'min_target_qty' => $campaign->min_target_qty]);

            return false;
        }

        $tiers = $campaign->tierSet() ?? throw new LogicException("Campaign {$campaign->id} has no tiers.");
        $tier = $tiers->tierFor($finalQty);
        $priceBefore = $tiers->current($campaign->committed_qty)->unitPriceHalalas;
        $captureBy = null;

        foreach ($counted as [$participation, $hold]) {
            // Everyone pays the final price, never more than they held.
            $unit = Money::sar($tier->unitPriceHalalas)->min(Money::sar($participation->hold_unit_price_halalas));
            // Any part of the hold that is not goods (a delivery fee, Q12) is charged as held.
            $extra = Money::sar($participation->hold_amount_halalas)->minus(Money::sar($participation->hold_unit_price_halalas)->times($participation->qty));

            $participation->final_unit_price_halalas = $unit->minor;
            $participation->capture_amount_halalas = $unit->times($participation->qty)->plus($extra)->minor;
            $participation->save();

            $captureBy = $captureBy === null ? $hold->captureCutoffAt() : $captureBy->min($hold->captureCutoffAt());
        }

        $campaign->final_qty = $finalQty;
        $campaign->final_tier_id = $tier->id;
        $campaign->final_unit_price_halalas = $tier->unitPriceHalalas;
        $campaign->capture_by = $captureBy;
        $campaign->closing_step = ClosingStep::AwaitingSupplier;
        $campaign->save();

        $this->events->record($campaign, CampaignEvents::PRICE_CHANGED, $actor, [
            'from_unit_price_halalas' => $priceBefore,
            'to_unit_price_halalas' => $tier->unitPriceHalalas,
            'tier' => ['min_qty' => $tier->minQty, 'max_qty' => $tier->maxQty],
            'tier_version' => $campaign->current_tier_version,
            'final' => true,
            'final_qty' => $finalQty,
        ]);
        $this->events->record($campaign, CampaignEvents::AWAITING_SUPPLIER, $actor, [
            'final_qty' => $finalQty,
            'capture_by' => $captureBy->toIso8601ZuluString(),
            'supplier_decision_due_at' => self::supplierDecisionDueAt($campaign)->toIso8601ZuluString(),
        ]);
        CampaignAwaitingSupplier::dispatch($campaign->id);
        CampaignProgressChanged::dispatch($campaign->id);

        return true;
    }

    /**
     * Step 4: no supplier decision by the deadline fails the campaign
     * (the proposed answer to the spec's open question).
     */
    private function checkSupplierDeadline(Campaign $campaign, CarbonImmutable $now): bool
    {
        $due = self::supplierDecisionDueAt($campaign);

        if ($now->lessThan($due)) {
            return false;
        }

        $this->lifecycle->fail($campaign, 'no_supplier_decision', Actor::system(), ['supplier_decision_due_at' => $due->toIso8601ZuluString()]);

        return false;
    }

    /**
     * Steps 5 and 6: capture every authorized join; when none is left,
     * check the captured quantity.
     */
    private function checkCaptures(Campaign $campaign): bool
    {
        $actor = Actor::system();
        $waiting = 0;

        foreach ($campaign->participations()->where('status', ParticipationStatus::Authorized)->orderBy('id')->lockForUpdate()->get() as $participation) {
            $hold = $this->liveHold($participation);

            if ($hold === null) {
                // Nothing left to capture (its hold expired): leave it out.
                $this->excludeFromCapture($participation, null, RejectionReason::CaptureCutoffPassed, $actor);

                continue;
            }

            CapturePayment::dispatch($hold->id)->afterCommit();
            $waiting++;
        }

        if ($waiting > 0) {
            return false;
        }

        $campaign->loadMissing('quotation');
        $campaign->captured_qty = (int) $campaign->participations()->where('status', ParticipationStatus::Captured)->sum('qty');
        $campaign->save();

        if ($campaign->captured_qty === 0) {
            $this->lifecycle->fail($campaign, 'captures_failed', $actor);

            return false;
        }

        if ($campaign->captured_qty < $campaign->min_target_qty || $campaign->captured_qty < $campaign->quotation->moq) {
            $campaign->closing_step = ClosingStep::NeedsAdmin;
            $campaign->save();
            $this->events->record($campaign, CampaignEvents::NEEDS_ADMIN, $actor, [
                'captured_qty' => $campaign->captured_qty,
                'min_target_qty' => $campaign->min_target_qty,
                'supplier_moq' => $campaign->quotation->moq,
            ]);
            CampaignNeedsAdmin::dispatch($campaign->id);

            return false;
        }

        $this->lifecycle->startPurchasing($campaign, $actor);

        return true;
    }

    /**
     * Step 8: tell every participant the outcome, then done.
     */
    private function notify(Campaign $campaign): bool
    {
        CampaignSucceeded::dispatch($campaign->id);
        $campaign->closing_step = ClosingStep::Done;
        $campaign->save();

        return false;
    }

    /**
     * The participation's hold that can be captured: authorized and not
     * being voided. Locked (lock order: after its participation).
     */
    private function liveHold(CampaignParticipation $participation): ?PaymentAuthorization
    {
        return PaymentAuthorization::query()
            ->where('participation_id', $participation->id)
            ->where('status', PaymentStatus::Authorized)
            ->whereNull('rejection_reason')
            ->lockForUpdate()
            ->first();
    }

    private function excludeFromCapture(CampaignParticipation $participation, ?PaymentAuthorization $hold, RejectionReason $reason, Actor $actor): void
    {
        if ($participation->status === ParticipationStatus::Authorized) {
            $participation->status = ParticipationStatus::CaptureFailed;
            $participation->cancel_reason = $reason->value;
            $participation->save();
            $this->history->record($participation, ParticipationStatus::Authorized, ParticipationStatus::CaptureFailed, $actor, $reason->value);
            ParticipationCaptureFailed::dispatch($participation->id);
        }

        if ($hold !== null) {
            $this->holds->captureFailed($hold, $reason, $actor);
        }
    }

    private function wakeIfCapturesDone(int $campaignId): void
    {
        $waiting = CampaignParticipation::query()
            ->where('campaign_id', $campaignId)
            ->where('status', ParticipationStatus::Authorized)
            ->exists();

        if (! $waiting) {
            CloseCampaign::dispatch($campaignId)->afterCommit();
        }
    }
}
