<?php

namespace App\Modules\Campaign\Models;

use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Enums\CloseReason;
use App\Modules\Campaign\Enums\ClosingStep;
use App\Modules\Campaign\Enums\DeliveryMode;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Pricing\TierSet;
use App\Modules\Supplier\Models\SupplierQuotation;
use Carbon\CarbonImmutable;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One group buy. `committed_qty` and `reserved_qty` are changed only by the
 * conditional UPDATEs in App\Modules\Campaign\Services\Joins and the
 * lifecycle, never by filling this model: they are left out of $fillable,
 * and code that saves a campaign must not touch them.
 *
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property int $product_id
 * @property int $supplier_quotation_id
 * @property string $title_ar
 * @property string $title_en
 * @property int $min_target_qty
 * @property int $max_qty
 * @property int $committed_qty
 * @property int $reserved_qty
 * @property int $version Grows with every UPDATE of the row; set by a trigger, never by code.
 * @property int|null $per_customer_max_qty
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $deadline_at
 * @property CarbonImmutable|null $original_deadline_at
 * @property CarbonImmutable|null $extended_at
 * @property int|null $extended_by_admin_id
 * @property string|null $extension_reason
 * @property CampaignStatus $status
 * @property CarbonImmutable|null $deal_confirmed_at
 * @property CloseReason|null $close_reason
 * @property ClosingStep|null $closing_step
 * @property CarbonImmutable|null $closed_at
 * @property int|null $final_qty
 * @property int|null $final_tier_id
 * @property int|null $final_unit_price_halalas
 * @property CarbonImmutable|null $capture_by
 * @property int|null $captured_qty
 * @property string|null $end_reason
 * @property int|null $current_tier_version
 * @property int|null $landed_cost_per_unit_halalas Snapshot taken at approval.
 * @property string $currency
 * @property DeliveryMode $delivery_mode
 * @property int $created_by_admin_id
 * @property int|null $approved_by_admin_id
 * @property CarbonImmutable|null $approved_at
 * @property string|null $margin_override_reason
 * @property int $lock_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Product $product
 * @property-read SupplierQuotation $quotation
 * @property-read Collection<int, ProductVariant> $variants
 * @property-read Collection<int, CampaignPriceTier> $currentTiers
 * @property-read AdminUser $creator
 * @property-read AdminUser|null $approver
 */
#[Fillable(['title_ar', 'title_en', 'min_target_qty', 'max_qty', 'per_customer_max_qty', 'starts_at', 'delivery_mode'])]
#[UseFactory(CampaignFactory::class)]
class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory, HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'committed_qty' => 0,
        'reserved_qty' => 0,
        'currency' => 'SAR',
        'delivery_mode' => 'warehouse',
        'lock_version' => 1,
    ];

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'close_reason' => CloseReason::class,
            'closing_step' => ClosingStep::class,
            'delivery_mode' => DeliveryMode::class,
            'min_target_qty' => 'integer',
            'max_qty' => 'integer',
            'committed_qty' => 'integer',
            'reserved_qty' => 'integer',
            'version' => 'integer',
            'per_customer_max_qty' => 'integer',
            'starts_at' => 'immutable_datetime',
            'deadline_at' => 'immutable_datetime',
            'original_deadline_at' => 'immutable_datetime',
            'extended_at' => 'immutable_datetime',
            'deal_confirmed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'capture_by' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'final_qty' => 'integer',
            'final_unit_price_halalas' => 'integer',
            'captured_qty' => 'integer',
            'current_tier_version' => 'integer',
            'landed_cost_per_unit_halalas' => 'integer',
            'lock_version' => 'integer',
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
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<SupplierQuotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotation::class, 'supplier_quotation_id');
    }

    /**
     * @return BelongsToMany<ProductVariant, $this>
     */
    public function variants(): BelongsToMany
    {
        return $this->belongsToMany(ProductVariant::class, 'campaign_variants')->orderBy('product_variants.id');
    }

    /**
     * The tier set in force: the latest version saved (locked at approval).
     *
     * @return HasMany<CampaignPriceTier, $this>
     */
    public function currentTiers(): HasMany
    {
        return $this->hasMany(CampaignPriceTier::class)
            ->whereColumn('campaign_price_tiers.version', 'campaigns.current_tier_version')
            ->join('campaigns', 'campaigns.id', '=', 'campaign_price_tiers.campaign_id')
            ->select('campaign_price_tiers.*')
            ->orderBy('campaign_price_tiers.min_qty');
    }

    /**
     * @return HasMany<CampaignParticipation, $this>
     */
    public function participations(): HasMany
    {
        return $this->hasMany(CampaignParticipation::class);
    }

    /**
     * @return HasMany<CampaignEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(CampaignEvent::class);
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    /**
     * @return BelongsTo<AdminUser, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by_admin_id');
    }

    /**
     * Campaigns customers can see: approved, and not a draft or awaiting
     * approval. A campaign cancelled before approval never was approved.
     *
     * @return Builder<self>
     */
    public static function visibleToCustomers(): Builder
    {
        return self::query()
            ->whereIn('status', CampaignStatus::visibleToCustomers())
            ->whereNotNull('approved_at');
    }

    /**
     * The tier set in force, or null before the first save.
     */
    public function tierSet(): ?TierSet
    {
        if ($this->current_tier_version === null) {
            return null;
        }

        return TierSet::fromModels($this->loadMissing('currentTiers')->currentTiers);
    }

    public function title(string $locale): string
    {
        return $locale === 'en' ? $this->title_en : $this->title_ar;
    }

    /**
     * Units still on sale.
     */
    public function remainingQty(): int
    {
        return max(0, $this->max_qty - $this->committed_qty - $this->reserved_qty);
    }

    /**
     * Whether a join may reserve units now. The join's conditional UPDATE
     * checks the same thing in SQL; this is for display and error messages.
     */
    public function isJoinable(?CarbonImmutable $now = null): bool
    {
        $now ??= CarbonImmutable::now();

        return $this->status === CampaignStatus::Active
            && $this->deadline_at !== null
            && $now->lessThan($this->deadline_at);
    }
}
