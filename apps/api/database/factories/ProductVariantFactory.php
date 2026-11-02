<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => fake()->unique()->bothify('SKU-####-??'),
            'name_ar' => 'أسود',
            'name_en' => 'Black',
            'option_values' => ['color' => 'black'],
            'weight_grams' => 1200,
            'length_mm' => null,
            'width_mm' => null,
            'height_mm' => null,
            'status' => 'active',
        ];
    }

    public function archived(): static
    {
        return $this->state(['status' => 'archived']);
    }
}
