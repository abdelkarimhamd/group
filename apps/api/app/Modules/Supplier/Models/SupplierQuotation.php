<?php

namespace App\Modules\Supplier\Models;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Supplier\Enums\QuotationStatus;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Factories\SupplierQuotationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One supplier quote for a product (or one variant), with the landed cost per
 * unit worked out for a basis quantity. Frozen once a campaign past draft uses
 * it, because that campaign's prices and margin were checked against it.
 *
 * @property int $id
 * @property string $uuid
 * @property int $supplier_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $moq
 * @property int $unit_cost_minor
 * @property Currency $currency
 * @property string $shipping_terms
 * @property int $lead_time_days
 * @property CarbonImmutable $valid_until
 * @property string $fx_rate_to_sar
 * @property int $landed_cost_basis_qty
 * @property array<string, int> $landed_cost_breakdown Halalas per unit, keyed by BREAKDOWN.
 * @property int $landed_cost_per_unit_halalas
 * @property string|null $attachment_disk
 * @property string|null $attachment_path
 * @property QuotationStatus $status
 * @property int $created_by_admin_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Supplier $supplier
 * @property-read Product $product
 * @property-read ProductVariant|null $variant
 */
#[Fillable(['moq', 'unit_cost_minor', 'currency', 'shipping_terms', 'lead_time_days', 'valid_until', 'fx_rate_to_sar', 'landed_cost_basis_qty'])]
#[UseFactory(SupplierQuotationFactory::class)]
class SupplierQuotation extends Model
{
    /** @use HasFactory<SupplierQuotationFactory> */
    use HasFactory, HasUuids;

    /**
     * The parts of the landed cost per unit, in the order they are shown.
     */
    public const BREAKDOWN = ['product', 'freight', 'customs_duty', 'import_vat', 'clearance', 'last_mile'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'valid_until' => 'immutable_date',
            'fx_rate_to_sar' => 'decimal:8',
            'landed_cost_breakdown' => 'array',
            'status' => QuotationStatus::class,
            // Cast so a value validated from a form field ("2") is returned as 2.
            'moq' => 'integer',
            'unit_cost_minor' => 'integer',
            'lead_time_days' => 'integer',
            'landed_cost_basis_qty' => 'integer',
            'landed_cost_per_unit_halalas' => 'integer',
        ];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function unitCost(): Money
    {
        return Money::of($this->unit_cost_minor, $this->currency);
    }

    public function landedCostPerUnit(): Money
    {
        return Money::sar($this->landed_cost_per_unit_halalas);
    }

    /**
     * Sets the breakdown and its total together, so they can never disagree.
     *
     * @param  array<string, int>  $halalas  Keyed by BREAKDOWN.
     */
    public function setLandedCost(array $halalas): void
    {
        $breakdown = [];
        $total = Money::zero();

        foreach (self::BREAKDOWN as $part) {
            $breakdown[$part] = (int) $halalas[$part];
            $total = $total->plus(Money::sar($breakdown[$part]));
        }

        // jsonb keeps its own key order, so compare ignoring order (==) and
        // leave an unchanged breakdown alone rather than mark it dirty.
        if (! $this->exists || $this->landed_cost_breakdown != $breakdown) {
            $this->landed_cost_breakdown = $breakdown;
        }

        $this->landed_cost_per_unit_halalas = $total->minor;
    }

    public function isExpiredOn(CarbonImmutable $today): bool
    {
        return $this->valid_until->lt($today->startOfDay());
    }
}
