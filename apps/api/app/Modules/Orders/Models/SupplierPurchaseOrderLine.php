<?php

namespace App\Modules\Orders\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The captured units of one variant on a purchase order.
 *
 * @property int $id
 * @property int $supplier_purchase_order_id
 * @property int $product_variant_id
 * @property int $qty
 * @property int $unit_cost_minor In the order's currency.
 * @property-read SupplierPurchaseOrder $purchaseOrder
 * @property-read ProductVariant $variant
 */
class SupplierPurchaseOrderLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_cost_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SupplierPurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierPurchaseOrder::class, 'supplier_purchase_order_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
