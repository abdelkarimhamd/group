<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name_ar' => 'خلاط كهربائي',
            'name_en' => 'Electric blender',
            'description_ar' => null,
            'description_en' => null,
            'alibaba_url' => 'https://www.alibaba.com/product-detail/'.fake()->numerify('##########').'.html',
            'hs_code' => '850940',
            'status' => 'draft',
            'created_by_admin_id' => AdminUser::factory(),
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => 'active'])
            ->has(ProductVariant::factory(), 'variants');
    }
}
