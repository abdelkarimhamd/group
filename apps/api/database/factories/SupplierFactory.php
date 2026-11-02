<?php

namespace Database\Factories;

use App\Modules\Identity\Models\AdminUser;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'alibaba_store_url' => 'https://'.fake()->unique()->domainWord().'.en.alibaba.com',
            'country' => 'CN',
            'contact_name' => null,
            'contact_channel' => 'Alibaba chat',
            'status' => 'prospect',
            'quality_rating' => null,
            'review_notes' => null,
            'created_by_admin_id' => AdminUser::factory(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'reviewed_by_admin_id' => AdminUser::factory(),
            'reviewed_at' => now(),
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => [
            'status' => 'blocked',
            'reviewed_by_admin_id' => AdminUser::factory(),
            'reviewed_at' => now(),
        ]);
    }
}
