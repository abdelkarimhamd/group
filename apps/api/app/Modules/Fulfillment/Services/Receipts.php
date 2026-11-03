<?php

namespace App\Modules\Fulfillment\Services;

use App\Exceptions\ApiException;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Services\CampaignEvents;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Fulfillment\Models\InboundReceipt;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\PurchaseOrderStatus;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Warehouse receipts (spec, E13): the supplier's goods are counted, the
 * purchase order becomes `received`, and customer orders the good units
 * cover become `ready_to_ship`, oldest first. Orders left uncovered by
 * missing or damaged units stay `processing` for an admin to refund.
 * Lock order: campaign, purchase order, customer orders.
 */
final class Receipts
{
    public function __construct(
        private readonly CampaignEvents $events,
        private readonly StatusHistory $history,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{warehouse_name: string, received_at: string, notes?: string|null, lines: list<array{product_variant_id: string, qty_received: int, qty_damaged: int}>}  $input
     */
    public function record(string $purchaseOrderUuid, array $input, AdminUser $by): InboundReceipt
    {
        return DB::transaction(function () use ($purchaseOrderUuid, $input, $by): InboundReceipt {
            $campaignId = SupplierPurchaseOrder::query()->where('uuid', $purchaseOrderUuid)->value('campaign_id')
                ?? throw (new ModelNotFoundException)->setModel(SupplierPurchaseOrder::class);
            $campaign = Campaign::query()->whereKey($campaignId)->lockForUpdate()->firstOrFail();
            $order = SupplierPurchaseOrder::query()->where('uuid', $purchaseOrderUuid)->lockForUpdate()->firstOrFail();

            if ($order->status !== PurchaseOrderStatus::Shipped) {
                throw new ApiException(409, 'invalid_transition');
            }

            $counted = $this->countedLines($order, $input['lines']);
            $actor = Actor::admin($by->id);
            $receivedAt = CarbonImmutable::parse($input['received_at']);

            $receipt = InboundReceipt::create([
                'supplier_purchase_order_id' => $order->id,
                'warehouse_name' => $input['warehouse_name'],
                'received_at' => $receivedAt,
                'received_by_admin_id' => $by->id,
                'notes' => $input['notes'] ?? null,
                'photo_s3_keys' => [],
            ]);

            foreach ($counted as $line) {
                $receipt->lines()->create($line);
            }

            $order->status = PurchaseOrderStatus::Received;
            $order->received_at = $receivedAt;
            $order->save();
            $this->history->record($order, PurchaseOrderStatus::Shipped, PurchaseOrderStatus::Received, $actor);

            $waiting = $this->readyOrders($campaign, $receipt->lines()->get()->keyBy('product_variant_id')->map->usable()->all(), $actor);
            $summary = [
                'purchase_order_id' => $order->uuid,
                'missing' => $receipt->lines->sum(fn ($line) => $line->missing()),
                'damaged' => $receipt->lines->sum('qty_damaged'),
                'orders_waiting' => $waiting,
            ];
            $this->events->record($campaign, CampaignEvents::GOODS_RECEIVED, $actor, $summary);
            $this->audit->record($actor, 'purchase_order.receive', $order, ['status' => PurchaseOrderStatus::Shipped->value], ['status' => PurchaseOrderStatus::Received->value] + $summary);

            return $receipt;
        });
    }

    /**
     * Every line of the purchase order counted once, and nothing else.
     *
     * @param  list<array{product_variant_id: string, qty_received: int, qty_damaged: int}>  $lines
     * @return list<array{product_variant_id: int, qty_expected: int, qty_received: int, qty_damaged: int}>
     */
    private function countedLines(SupplierPurchaseOrder $order, array $lines): array
    {
        $variants = ProductVariant::query()
            ->whereIn('uuid', array_column($lines, 'product_variant_id'))
            ->pluck('id', 'uuid');
        $expected = $order->lines()->pluck('qty', 'product_variant_id');
        $counted = [];

        foreach ($lines as $line) {
            $variantId = $variants[$line['product_variant_id']] ?? null;

            if ($variantId === null || ! $expected->has($variantId)) {
                throw ApiException::unprocessable('receipt_lines_mismatch', 'lines');
            }

            $counted[] = [
                'product_variant_id' => $variantId,
                'qty_expected' => (int) $expected[$variantId],
                'qty_received' => (int) $line['qty_received'],
                'qty_damaged' => (int) $line['qty_damaged'],
            ];
        }

        if (count($counted) !== $expected->count()) {
            throw ApiException::unprocessable('receipt_lines_mismatch', 'lines');
        }

        return $counted;
    }

    /**
     * Customer orders still being processed become ready to ship while the
     * good units of their variant cover them, oldest first. Returns the
     * order numbers left waiting.
     *
     * @param  array<int, int>  $usable  good units by variant ID
     * @return list<string>
     */
    private function readyOrders(Campaign $campaign, array $usable, Actor $actor): array
    {
        $waiting = [];
        $orders = CustomerOrder::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', OrderStatus::Processing)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($orders as $customerOrder) {
            $left = $usable[$customerOrder->product_variant_id] ?? 0;

            if ($left < $customerOrder->qty) {
                $waiting[] = $customerOrder->order_number;

                continue;
            }

            $usable[$customerOrder->product_variant_id] = $left - $customerOrder->qty;
            $customerOrder->status = OrderStatus::ReadyToShip;
            $customerOrder->save();
            $this->history->record($customerOrder, OrderStatus::Processing, OrderStatus::ReadyToShip, $actor, 'goods_received');
        }

        return $waiting;
    }
}
