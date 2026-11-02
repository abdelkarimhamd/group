<?php

namespace App\Modules\Catalog\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A product photo on the media disk. Optionally tied to one variant.
 *
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $disk
 * @property string $path
 * @property int $sort_order
 * @property string|null $alt_ar
 * @property string|null $alt_en
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read ProductVariant|null $variant
 */
#[Fillable(['sort_order', 'alt_ar', 'alt_en'])]
class ProductImage extends Model
{
    use HasUuids;

    /**
     * Integer columns are cast so a value validated from a form field such as
     * "2" is returned as 2, as the contract types it.
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'sort_order' => 0,
    ];

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
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
