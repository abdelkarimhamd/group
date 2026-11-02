<?php

namespace App\Modules\Catalog\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browsing category. Categories nest through parent_id.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $parent_id
 * @property string $slug
 * @property string $name_ar
 * @property string $name_en
 * @property int $sort_order
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Category|null $parent
 */
#[Fillable(['slug', 'name_ar', 'name_en', 'sort_order'])]
#[UseFactory(CategoryFactory::class)]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasUuids;

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
     * @return BelongsTo<Category, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function name(string $locale): string
    {
        return $locale === 'en' ? $this->name_en : $this->name_ar;
    }
}
