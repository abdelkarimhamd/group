<?php

namespace App\Modules\Orders\Models;

use App\Modules\Identity\Models\AdminUser;
use App\Modules\Orders\Enums\PurchaseOrderDocumentKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file kept with a supplier purchase order: the Alibaba invoice, a
 * payment receipt, shipping or customs papers. Stored on the private
 * documents disk and read through signed links.
 *
 * @property int $id
 * @property string $uuid
 * @property int $supplier_purchase_order_id
 * @property PurchaseOrderDocumentKind $kind
 * @property string $disk
 * @property string $path
 * @property string $file_name
 * @property int $size_bytes
 * @property int $uploaded_by_admin_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read SupplierPurchaseOrder $purchaseOrder
 * @property-read AdminUser $uploadedBy
 */
class PurchaseOrderDocument extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'kind' => PurchaseOrderDocumentKind::class,
            'size_bytes' => 'integer',
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
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'uploaded_by_admin_id');
    }
}
