<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\VariantStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One buyable version of a product (a colour, a size).
 *
 * @property int $id
 * @property string $uuid
 * @property int $product_id
 * @property string $sku
 * @property string $name_ar
 * @property string $name_en
 * @property array<string, string> $option_values
 * @property int|null $weight_grams
 * @property int|null $length_mm
 * @property int|null $width_mm
 * @property int|null $height_mm
 * @property VariantStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Fillable(['sku', 'name_ar', 'name_en', 'option_values', 'weight_grams', 'length_mm', 'width_mm', 'height_mm'])]
#[UseFactory(ProductVariantFactory::class)]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory, HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'option_values' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'status' => VariantStatus::class,
        ];
    }

    /**
     * A map such as {"color": "black"}. Always stored as a JSON object, even
     * when empty, because the column's CHECK requires one.
     *
     * @return Attribute<array<string, string>, array<string, string>>
     */
    protected function optionValues(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null ? [] : (array) json_decode($value, true),
            set: fn (array $value): string => json_encode((object) $value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
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
}
