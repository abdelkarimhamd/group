<?php

namespace App\Modules\Campaign\Services;

use App\Exceptions\ApiException;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Enums\ClosingStep;
use App\Modules\Campaign\Enums\ParticipationStatus;
use App\Modules\Campaign\Jobs\ExecuteCampaignCancellation;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Finance\Enums\ApprovalStatus;
use App\Modules\Finance\Enums\ApprovalType;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PurchaseOrderStatus;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\RefundReason;
use App\Modules\Payments\Enums\TransactionStatus;
use App\Modules\Payments\Enums\TransactionType;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\Refunds;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cancelling a campaign after captures started, once a finance admin
 * approved it (spec, Campaign states: cancelled). In one transaction with
 * the campaign locked: refunds of captured joins still waiting for approval
 * are withdrawn, every captured join gets an approved refund of what is
 * left on its payment (sent after commit), orders not yet shipped are
 * cancelled, the supplier purchase order is cancelled, joins still holding
 * a card are released, and the campaign is cancelled.
 *
 * From approval until it is carried out (executed_at) the campaign waits:
 * no new capture starts, closing does not move on, customers are not told
 * the deal succeeded, and the purchase order does not advance. A capture
 * already in flight is let finish first, so no money is taken after the
 * refunds are made. Lock order: campaign, purchase order, participation,
 * payment, order, refund.
 */
final class CampaignCancellations
{
    public function __construct(
        private readonly CampaignLifecycle $lifecycle,
        private readonly Refunds $refunds,
        private readonly StatusHistory $history,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Whether an approved cancellation can still be carried out.
     */
    public static function cancellable(Campaign $campaign): bool
    {
        return $campaign->status === CampaignStatus::Purchasing
            || ($campaign->status === CampaignStatus::Closing && $campaign->closing_step !== ClosingStep::Notifying && $campaign->closing_step !== ClosingStep::Done);
    }

    /**
     * An approved cancellation of the campaign is still to be carried out.
     * Ask with the campaign locked: approval locks it too.
     */
    public static function waiting(Campaign $campaign): bool
    {
        return self::waitingRequest($campaign) !== null;
    }

    /**
     * The approved cancellation still to be carried out, if any.
     */
    public static function waitingRequest(Campaign $campaign): ?ApprovalRequest
    {
        return ApprovalRequest::query()
            ->where('type', ApprovalType::CampaignCancellation)
            ->where('subject_type', $campaign->getMorphClass())
            ->where('subject_id', $campaign->getKey())
            ->where('status', ApprovalStatus::Approved)
            ->whereNull('executed_at')
            ->first();
    }

    /**
     * The request was approved: carry it out after commit. Call with the
     * campaign locked.
     *
     * @throws ApiException 409 invalid_transition when the campaign moved on (fulfilling, ended)
     */
    public function approve(Campaign $campaign, ApprovalRequest $request): void
    {
        if (! self::cancellable($campaign)) {
            throw new ApiException(409, 'invalid_transition');
        }

        ExecuteCampaignCancellation::dispatch($request->id)->afterCommit();
    }

    /**
     * Returns false while a capture is in flight: try again later.
     */
    public function execute(int $approvalRequestId): bool
    {
        return DB::transaction(function () use ($approvalRequestId): bool {
            $found = ApprovalRequest::query()->find($approvalRequestId);

            if ($found === null || $found->type !== ApprovalType::CampaignCancellation || $found->status !== ApprovalStatus::Approved || $found->executed_at !== null) {
                return true;
            }

            // The subject before the request, as when it was decided.
            $campaign = Campaign::query()->whereKey($found->subject_id)->lockForUpdate()->firstOrFail();
            $request = ApprovalRequest::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();

            if ($request->executed_at !== null) {
                return true;
            }

            $actor = Actor::admin((int) $request->decided_by_admin_id);

            if ($campaign->status === CampaignStatus::Cancelled) {
                $this->carriedOut($request);

                return true;
            }

            if (! self::cancellable($campaign)) {
                // Waiting campaigns cannot move on, so this means a bug or a hand-made change.
                Log::critical('campaigns.cancellation.not_possible', ['campaign' => $campaign->uuid, 'status' => $campaign->status->value, 'approval_request' => $request->uuid]);
                $this->audit->record($actor, 'approval.not_carried_out', $request, null, [
                    'type' => $request->type->value,
                    'campaign_status' => $campaign->status->value,
                    'closing_step' => $campaign->closing_step?->value,
                ]);
                $this->carriedOut($request);

                return true;
            }

            if ($this->captureInFlight($campaign)) {
                Log::info('campaigns.cancellation.waiting_for_captures', ['campaign' => $campaign->uuid]);

                return false;
            }

            $purchaseOrder = SupplierPurchaseOrder::query()->where('campaign_id', $campaign->id)->lockForUpdate()->first();
            $refunded = 0;
            $withdrawn = 0;
            $cancelledOrders = 0;

            $captured = CampaignParticipation::query()
                ->where('campaign_id', $campaign->id)
                ->where('status', ParticipationStatus::Captured)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($captured as $participation) {
                $payment = PaymentAuthorization::query()
                    ->where('participation_id', $participation->id)
                    ->whereIn('status', [PaymentStatus::Captured, PaymentStatus::PartiallyRefunded])
                    ->lockForUpdate()
                    ->first();
                $order = CustomerOrder::query()->where('participation_id', $participation->id)->lockForUpdate()->first();

                if ($payment !== null) {
                    // A refund still waiting for approval would be left out of the full refund.
                    $withdrawn += $this->refunds->withdrawPending($payment, 'campaign_cancelled', $actor);

                    if ($this->refunds->createApproved($payment, $order, RefundReason::CampaignCancelledAfterCapture, $request, $actor) !== null) {
                        $refunded++;
                    }
                }

                if ($order !== null && in_array($order->status, [OrderStatus::Confirmed, OrderStatus::Processing, OrderStatus::ReadyToShip], true)) {
                    $from = $order->status;
                    $order->status = OrderStatus::Cancelled;
                    $order->save();
                    $this->history->record($order, $from, OrderStatus::Cancelled, $actor, 'campaign_cancelled');
                    $cancelledOrders++;
                }
            }

            $supplierPaid = false;

            if ($purchaseOrder !== null && ! $purchaseOrder->status->isClosed()) {
                $from = $purchaseOrder->status;
                $supplierPaid = $from === PurchaseOrderStatus::Paid;
                $purchaseOrder->status = PurchaseOrderStatus::Cancelled;
                $purchaseOrder->cancelled_at = CarbonImmutable::now();
                $purchaseOrder->save();
                $this->history->record($purchaseOrder, $from, PurchaseOrderStatus::Cancelled, $actor, 'campaign_cancelled');
                $this->audit->record($actor, 'purchase_order.cancel', $purchaseOrder, ['status' => $from->value], [
                    'status' => PurchaseOrderStatus::Cancelled->value,
                    'supplier_paid' => $supplierPaid,
                ]);

                if ($supplierPaid) {
                    // The supplier was paid; getting that money back is a business matter.
                    Log::critical('campaigns.cancellation.supplier_already_paid', ['campaign' => $campaign->uuid, 'purchase_order' => $purchaseOrder->po_number]);
                }
            }

            $this->lifecycle->cancelApproved($campaign, $request, $actor, [
                'refunds' => $refunded,
                'withdrawn_refund_requests' => $withdrawn,
                'cancelled_orders' => $cancelledOrders,
                'purchase_order_cancelled' => $purchaseOrder !== null && $purchaseOrder->status === PurchaseOrderStatus::Cancelled,
                'supplier_paid' => $supplierPaid,
            ]);
            $this->carriedOut($request);

            return true;
        });
    }

    private function carriedOut(ApprovalRequest $request): void
    {
        $request->executed_at = CarbonImmutable::now();
        $request->save();
    }

    /**
     * A capture attempt of the campaign is written but not yet answered.
     */
    private function captureInFlight(Campaign $campaign): bool
    {
        return PaymentTransaction::query()
            ->where('type', TransactionType::Capture)
            ->where('status', TransactionStatus::Pending)
            ->whereIn('payment_authorization_id', PaymentAuthorization::query()
                ->select('payment_authorizations.id')
                ->join('campaign_participations', 'campaign_participations.id', '=', 'payment_authorizations.participation_id')
                ->where('campaign_participations.campaign_id', $campaign->id))
            ->exists();
    }
}
