<?php

namespace App\Modules\Fulfillment\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One variant's count: expected (the purchase order line), received, and
 * damaged among those received.
 *
 * @property int $id
 * @property int $inbound_receipt_id
 * @property int $product_variant_id
 * @property int $qty_expected
 * @property int $qty_received
 * @property int $qty_damaged
 * @property-read ProductVariant $variant
 */
class InboundReceiptLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty_expected' => 'integer',
            'qty_received' => 'integer',
            'qty_damaged' => 'integer',
        ];
    }

    /**
     * Units expected but not delivered.
     */
    public function missing(): int
    {
        return max(0, $this->qty_expected - $this->qty_received);
    }

    /**
     * Units that can be sent to customers.
     */
    public function usable(): int
    {
        return $this->qty_received - $this->qty_damaged;
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
