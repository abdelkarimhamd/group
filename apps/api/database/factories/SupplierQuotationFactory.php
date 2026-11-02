<?php

namespace Database\Factories;

use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierQuotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierQuotation>
 */
class SupplierQuotationFactory extends Factory
{
    protected $model = SupplierQuotation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // 8.00 USD at 3.75 = 30.00 SAR, plus freight, duty, VAT, clearance, last mile.
        $breakdown = [
            'product' => 3000,
            'freight' => 800,
            'customs_duty' => 150,
            'import_vat' => 590,
            'clearance' => 100,
            'last_mile' => 1500,
        ];

        return [
            'supplier_id' => Supplier::factory(),
            'product_id' => Product::factory(),
            'product_variant_id' => null,
            'moq' => 100,
            'unit_cost_minor' => 800,
            'currency' => 'USD',
            'shipping_terms' => 'FOB',
            'lead_time_days' => 30,
            'valid_until' => now('Asia/Riyadh')->addDays(30)->toDateString(),
            'fx_rate_to_sar' => '3.75000000',
            'landed_cost_basis_qty' => 200,
            'landed_cost_breakdown' => $breakdown,
            'landed_cost_per_unit_halalas' => array_sum($breakdown),
            'status' => 'draft',
            'created_by_admin_id' => AdminUser::factory(),
        ];
    }

    public function accepted(): static
    {
        return $this->state(['status' => 'accepted']);
    }

    public function expiredOn(string $date): static
    {
        return $this->state(['valid_until' => $date]);
    }
}
