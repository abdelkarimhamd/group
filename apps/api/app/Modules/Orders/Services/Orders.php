<?php

namespace App\Modules\Orders\Services;

use App\Modules\Campaign\Enums\ParticipationStatus;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Campaign\Services\CampaignNotices;
use App\Modules\Notifications\Enums\NotificationTemplate;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Support\Actor;
use App\Support\Audit\StatusHistory;
use App\Support\Money\Money;
use LogicException;

/**
 * Customer orders (spec, Orders and invoices: customer_orders). An order
 * is made only after a capture succeeds, one per participation, in the
 * transaction that records the capture, with its tax invoice.
 */
final class Orders
{
    public function __construct(
        private readonly DocumentNumbers $numbers,
        private readonly Invoices $invoices,
        private readonly StatusHistory $history,
        private readonly Notifier $notifier,
    ) {}

    /**
     * The order for a join whose hold was just captured. Call inside the
     * transaction that records the capture, with the participation and the
     * payment locked. Returns the existing order when there is one.
     */
    public function createForCapture(CampaignParticipation $participation, PaymentAuthorization $payment, Actor $actor): CustomerOrder
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isEmpty()) {
            throw new LogicException('Create an order inside the transaction that records its capture.');
        }

        $existing = CustomerOrder::query()->where('participation_id', $participation->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($participation->status !== ParticipationStatus::Captured || $payment->status !== PaymentStatus::Captured || $payment->participation_id !== $participation->id) {
            throw new LogicException("Participation {$participation->id} has no capture to make an order from.");
        }

        $unit = Money::sar((int) $participation->final_unit_price_halalas);
        $goods = $unit->times($participation->qty);
        $total = Money::sar($payment->captured_amount_halalas);
        // The rest of the capture is the non-goods part of the hold (a delivery fee, Q12).
        $fee = $total->minus($goods);

        if ($fee->isNegative()) {
            throw new LogicException("Payment {$payment->id} captured less than the goods of participation {$participation->id}.");
        }

        $participation->loadMissing('variant.product');
        $variant = $participation->variant;
        $items = [[
            'description_ar' => trim($variant->product->name_ar.' - '.$variant->name_ar),
            'description_en' => trim($variant->product->name_en.' - '.$variant->name_en),
            'qty' => $participation->qty,
            'unit_price_halalas' => $unit->minor,
        ]];

        if (! $fee->isZero()) {
            $items[] = ['description_ar' => 'رسوم التوصيل', 'description_en' => 'Delivery', 'qty' => 1, 'unit_price_halalas' => $fee->minor];
        }

        $rate = (int) config('groupbuy.vat_rate_bp');
        $order = CustomerOrder::create([
            'order_number' => $this->numbers->order(),
            'user_id' => $participation->user_id,
            'campaign_id' => $participation->campaign_id,
            'participation_id' => $participation->id,
            'payment_authorization_id' => $payment->id,
            'product_variant_id' => $participation->product_variant_id,
            'qty' => $participation->qty,
            'unit_price_halalas' => $unit->minor,
            'goods_total_halalas' => $goods->minor,
            'delivery_fee_halalas' => $fee->minor,
            'total_halalas' => $total->minor,
            // Set from the invoice below, so the order and its invoice agree to the halala.
            'vat_halalas' => 0,
            'vat_rate_bp' => $rate,
            'shipping_address' => $participation->delivery_address,
            'status' => OrderStatus::Confirmed,
        ]);
        $this->history->record($order, null, OrderStatus::Confirmed, $actor);

        $invoice = $this->invoices->issueTaxInvoice($order, $items, $actor);
        $order->vat_halalas = $invoice->vat_halalas;
        $order->save();

        $participation->loadMissing(['user', 'campaign']);
        $this->notifier->send($participation->user, NotificationTemplate::Charged, $order, [
            'campaign' => CampaignNotices::title($participation->campaign),
            'qty' => $order->qty,
            'total_halalas' => $order->total_halalas,
            'order' => $order->order_number,
        ], "charged:customer_order:{$order->id}");

        return $order;
    }
}
