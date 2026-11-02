<?php

namespace App\Modules\Orders\Models;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Enums\InvoiceType;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One captured join (spec, Orders and invoices: customer_orders). Created
 * in the transaction that records the capture; the amounts never change.
 *
 * @property int $id
 * @property string $uuid
 * @property string $order_number
 * @property int $user_id
 * @property int $campaign_id
 * @property int $participation_id
 * @property int $payment_authorization_id
 * @property int $product_variant_id
 * @property int $qty
 * @property int $unit_price_halalas VAT-inclusive final price.
 * @property int $goods_total_halalas
 * @property int $delivery_fee_halalas
 * @property int $total_halalas Equals the amount captured.
 * @property int $vat_halalas The VAT contained in the total.
 * @property int $vat_rate_bp
 * @property string $currency
 * @property array<string, mixed> $shipping_address Snapshot taken at join.
 * @property OrderStatus $status
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $return_window_ends_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $user
 * @property-read Campaign $campaign
 * @property-read CampaignParticipation $participation
 * @property-read PaymentAuthorization $payment
 * @property-read ProductVariant $variant
 * @property-read Collection<int, Invoice> $invoices
 * @property-read Collection<int, Refund> $refunds
 */
class CustomerOrder extends Model
{
    use HasUuids;

    protected $guarded = ['id', 'uuid'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'confirmed',
        'currency' => 'SAR',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'qty' => 'integer',
            'unit_price_halalas' => 'integer',
            'goods_total_halalas' => 'integer',
            'delivery_fee_halalas' => 'integer',
            'total_halalas' => 'integer',
            'vat_halalas' => 'integer',
            'vat_rate_bp' => 'integer',
            'shipping_address' => 'array',
            'delivered_at' => 'immutable_datetime',
            'return_window_ends_at' => 'immutable_datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Campaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /**
     * @return BelongsTo<CampaignParticipation, $this>
     */
    public function participation(): BelongsTo
    {
        return $this->belongsTo(CampaignParticipation::class, 'participation_id');
    }

    /**
     * @return BelongsTo<PaymentAuthorization, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(PaymentAuthorization::class, 'payment_authorization_id');
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Oldest first: the tax invoice, then any credit notes.
     *
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderBy('id');
    }

    /**
     * @return HasOne<Invoice, $this>
     */
    public function taxInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->where('type', InvoiceType::SimplifiedTaxInvoice);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('id');
    }
}
