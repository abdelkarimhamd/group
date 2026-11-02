<?php

namespace App\Modules\Orders\Models;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Fulfillment\Models\InboundReceipt;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Orders\Enums\PurchaseOrderStatus;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierQuotation;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The one bulk Alibaba order of a successful campaign (spec, E12),
 * created by the closing job for the captured units.
 *
 * @property int $id
 * @property string $uuid
 * @property string $po_number
 * @property int $campaign_id
 * @property int $supplier_id
 * @property int $supplier_quotation_id
 * @property string|null $alibaba_order_ref
 * @property int $total_minor In the supplier's currency.
 * @property Currency $currency
 * @property string|null $fx_rate_to_sar Exact decimal; null until known.
 * @property int|null $total_halalas The SAR value, for the ledger.
 * @property PurchaseOrderStatus $status
 * @property CarbonImmutable|null $placed_at
 * @property CarbonImmutable|null $supplier_confirmed_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $shipped_at
 * @property CarbonImmutable|null $received_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $freight_forwarder
 * @property string|null $freight_tracking_ref
 * @property CarbonImmutable|null $eta
 * @property int|null $placed_by_admin_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Campaign $campaign
 * @property-read Supplier $supplier
 * @property-read SupplierQuotation $quotation
 * @property-read AdminUser|null $placedBy
 * @property-read Collection<int, SupplierPurchaseOrderLine> $lines
 * @property-read Collection<int, InboundReceipt> $receipts
 */
class SupplierPurchaseOrder extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'currency' => Currency::class,
            'total_minor' => 'integer',
            'total_halalas' => 'integer',
            'fx_rate_to_sar' => 'decimal:8',
            'placed_at' => 'immutable_datetime',
            'supplier_confirmed_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'shipped_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'eta' => 'immutable_date',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function total(): Money
    {
        return Money::of($this->total_minor, $this->currency);
    }

    /**
     * The total in SAR at the recorded rate, or null while the rate is unknown.
     */
    public function totalInSar(): ?Money
    {
        return $this->fx_rate_to_sar === null ? null : $this->total()->convertTo(Currency::SAR, $this->fx_rate_to_sar);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<SupplierQuotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotation::class, 'supplier_quotation_id');
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function placedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'placed_by_admin_id');
    }

    /**
     * Warehouse counts; one in the pilot.
     *
     * @return HasMany<InboundReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(InboundReceipt::class)->orderBy('id');
    }

    /**
     * @return HasMany<SupplierPurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierPurchaseOrderLine::class)->orderBy('id');
    }
}
