<?php

namespace App\Modules\Fulfillment\Models;

use App\Modules\Identity\Models\AdminUser;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods counted at the warehouse for one supplier purchase order.
 *
 * @property int $id
 * @property string $uuid
 * @property int $supplier_purchase_order_id
 * @property string $warehouse_name
 * @property CarbonImmutable $received_at
 * @property int $received_by_admin_id
 * @property string|null $notes
 * @property list<string> $photo_s3_keys
 * @property-read SupplierPurchaseOrder $purchaseOrder
 * @property-read AdminUser $receivedBy
 * @property-read Collection<int, InboundReceiptLine> $lines
 */
class InboundReceipt extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'received_at' => 'immutable_datetime',
            'photo_s3_keys' => 'array',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return BelongsTo<SupplierPurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierPurchaseOrder::class, 'supplier_purchase_order_id');
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'received_by_admin_id');
    }

    /**
     * @return HasMany<InboundReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InboundReceiptLine::class)->orderBy('id');
    }
}
