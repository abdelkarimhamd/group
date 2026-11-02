<?php

namespace App\Modules\Orders\Services;

use App\Exceptions\ApiException;
use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Enums\ClosingStep;
use App\Modules\Campaign\Enums\ParticipationStatus;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignEvent;
use App\Modules\Campaign\Services\CampaignEvents;
use App\Modules\Campaign\Services\CampaignLifecycle;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PurchaseOrderStatus;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\Changes;
use App\Support\Audit\StatusHistory;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Supplier purchase orders (spec, E12): one per successful campaign, for
 * the captured units, recorded and moved forward by hand as the Alibaba
 * order goes through. Lock order: campaign, then purchase order, then
 * customer orders.
 */
final class PurchaseOrders
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly CampaignLifecycle $lifecycle,
        private readonly StatusHistory $history,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * The bulk order for the captured units, as a draft at the cost the
     * supplier reconfirmed. Call in the transaction that moves the campaign
     * to purchasing, with the campaign locked. A campaign has one: calling
     * again returns it.
     */
    public function createForCampaign(Campaign $campaign, Actor $actor): SupplierPurchaseOrder
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isEmpty()) {
            throw new LogicException('Create the purchase order in the transaction that starts purchasing.');
        }

        $existing = SupplierPurchaseOrder::query()->where('campaign_id', $campaign->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $campaign->loadMissing('quotation');
        $quotation = $campaign->quotation;
        $confirmed = CampaignEvent::query()
            ->where('campaign_id', $campaign->id)
            ->where('event_type', CampaignEvents::SUPPLIER_CONFIRMED)
            ->latest('id')
            ->first()?->payload ?? [];
        $currency = Currency::from((string) ($confirmed['currency'] ?? $quotation->currency->value));
        $unitCost = Money::of((int) ($confirmed['unit_cost_minor'] ?? $quotation->unit_cost_minor), $currency);

        /** @var list<array{product_variant_id: int, qty: int}> $lines */
        $lines = $campaign->participations()
            ->where('status', ParticipationStatus::Captured)
            ->selectRaw('product_variant_id, SUM(qty)::int AS qty')
            ->groupBy('product_variant_id')
            ->orderBy('product_variant_id')
            ->toBase()
            ->get()
            ->map(fn (object $line) => ['product_variant_id' => (int) $line->product_variant_id, 'qty' => (int) $line->qty])
            ->all();

        if ($lines === []) {
            throw new LogicException("Campaign {$campaign->id} has no captured units to buy.");
        }

        $total = Money::zero($currency);
        foreach ($lines as $line) {
            $total = $total->plus($unitCost->times($line['qty']));
        }

        // The quote's rate holds for the quote's currency; another currency's
        // rate is recorded by hand before the order is placed.
        $rate = match (true) {
            $currency === Currency::SAR => '1',
            $currency === $quotation->currency => $quotation->fx_rate_to_sar,
            default => null,
        };

        $order = SupplierPurchaseOrder::create([
            'po_number' => $this->numbers->purchaseOrder(),
            'campaign_id' => $campaign->id,
            'supplier_id' => $quotation->supplier_id,
            'supplier_quotation_id' => $quotation->id,
            'total_minor' => $total->minor,
            'currency' => $currency,
            'fx_rate_to_sar' => $rate,
            'total_halalas' => $rate === null ? null : $total->convertTo(Currency::SAR, $rate)->minor,
        ]);

        foreach ($lines as $line) {
            $order->lines()->create($line + ['unit_cost_minor' => $unitCost->minor]);
        }

        $this->history->record($order, null, PurchaseOrderStatus::Draft, $actor, 'captures_completed');

        return $order;
    }

    /**
     * Record the Alibaba order details and freight (PATCH: fields left out
     * keep their value). Changing the currency drops a rate that no longer
     * applies; the SAR total follows the total and the rate.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(string $uuid, array $input, AdminUser $by): SupplierPurchaseOrder
    {
        return DB::transaction(function () use ($uuid, $input, $by): SupplierPurchaseOrder {
            $order = SupplierPurchaseOrder::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            if ($order->status->isClosed()) {
                throw new ApiException(409, 'invalid_transition');
            }

            $order->fill(array_intersect_key($input, array_flip([
                'alibaba_order_ref', 'total_minor', 'freight_forwarder', 'freight_tracking_ref', 'eta',
            ])));

            if (array_key_exists('currency', $input) && $input['currency'] !== $order->currency->value) {
                $order->currency = Currency::from($input['currency']);
                $order->fx_rate_to_sar = $order->currency === Currency::SAR ? '1' : null;
            }

            if (array_key_exists('fx_rate_to_sar', $input)) {
                $order->fx_rate_to_sar = $input['fx_rate_to_sar'];
            }

            $order->total_halalas = $order->totalInSar()?->minor;
            $this->assertComplete($order);

            [$before, $after] = Changes::pending($order);

            if ($after === []) {
                return $order;
            }

            $order->save();
            $this->audit->record(Actor::admin($by->id), 'purchase_order.update', $order, $before, $after);

            return $order;
        });
    }

    /**
     * Move the order forward to `placed`, `supplier_confirmed`, `paid` or
     * `shipped` (steps may be skipped, never undone). Past draft the order
     * needs its Alibaba reference and a SAR value; shipping needs a freight
     * tracking reference and moves the campaign to fulfilling. Placing it
     * moves the campaign's confirmed customer orders to processing.
     */
    public function advance(string $uuid, PurchaseOrderStatus $to, ?CarbonImmutable $occurredAt, ?string $note, AdminUser $by): SupplierPurchaseOrder
    {
        if (! in_array($to, PurchaseOrderStatus::advanceable(), true)) {
            throw new LogicException("A purchase order is not moved to {$to->value} by hand.");
        }

        return DB::transaction(function () use ($uuid, $to, $occurredAt, $note, $by): SupplierPurchaseOrder {
            $campaignId = SupplierPurchaseOrder::query()->where('uuid', $uuid)->value('campaign_id') ?? throw (new ModelNotFoundException)->setModel(SupplierPurchaseOrder::class);
            $campaign = Campaign::query()->whereKey($campaignId)->lockForUpdate()->firstOrFail();
            $order = SupplierPurchaseOrder::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();
            $from = $order->status;

            if ($from->isClosed() || $to->rank() <= $from->rank() || $campaign->status !== CampaignStatus::Purchasing) {
                throw new ApiException(409, 'invalid_transition');
            }

            // The campaign leaves purchasing only once its customers were told it succeeded.
            if ($to === PurchaseOrderStatus::Shipped && $campaign->closing_step !== ClosingStep::Done) {
                throw new ApiException(409, 'invalid_transition');
            }

            $at = $occurredAt ?? CarbonImmutable::now();
            $actor = Actor::admin($by->id);
            $order->status = $to;
            $order->setAttribute((string) $to->timestampColumn(), $at);
            $order->placed_at ??= $at;
            $order->placed_by_admin_id ??= $by->id;
            $this->assertComplete($order);
            $order->save();

            $this->history->record($order, $from, $to, $actor, null, $note === null ? null : ['note' => $note]);
            $this->audit->record($actor, 'purchase_order.advance', $order,
                ['status' => $from->value],
                ['status' => $to->value, 'occurred_at' => $at->toIso8601ZuluString(), 'note' => $note],
            );

            if ($from === PurchaseOrderStatus::Draft) {
                $this->startProcessingOrders($campaign, $actor);
            }

            if ($to === PurchaseOrderStatus::Shipped) {
                $this->lifecycle->startFulfilling($campaign, $actor, [
                    'purchase_order_id' => $order->uuid,
                    'freight_tracking_ref' => $order->freight_tracking_ref,
                ]);
            }

            return $order;
        });
    }

    /**
     * What a purchase order must carry for its status: past draft, the
     * Alibaba reference and a SAR value; once shipped, a tracking reference.
     */
    private function assertComplete(SupplierPurchaseOrder $order): void
    {
        if ($order->status === PurchaseOrderStatus::Draft || $order->status === PurchaseOrderStatus::Cancelled) {
            return;
        }

        if ($order->alibaba_order_ref === null || trim($order->alibaba_order_ref) === '') {
            throw ApiException::unprocessable('purchase_order_incomplete', 'alibaba_order_ref');
        }

        if ($order->total_halalas === null) {
            throw ApiException::unprocessable('purchase_order_incomplete', 'fx_rate_to_sar');
        }

        if (in_array($order->status, [PurchaseOrderStatus::Shipped, PurchaseOrderStatus::Received], true)
            && ($order->freight_tracking_ref === null || trim($order->freight_tracking_ref) === '')) {
            throw ApiException::unprocessable('purchase_order_incomplete', 'freight_tracking_ref');
        }
    }

    /**
     * The supplier order is placed: every confirmed customer order of the
     * campaign is now being processed.
     */
    private function startProcessingOrders(Campaign $campaign, Actor $actor): void
    {
        $orders = CustomerOrder::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', OrderStatus::Confirmed)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($orders as $customerOrder) {
            $customerOrder->status = OrderStatus::Processing;
            $customerOrder->save();
            $this->history->record($customerOrder, OrderStatus::Confirmed, OrderStatus::Processing, $actor, 'purchase_order_placed');
        }
    }
}
