<?php

namespace Database\Factories;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignPriceTier;
use App\Modules\Catalog\Models\Product;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierQuotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Campaigns for tests. Counters (committed_qty, reserved_qty) start at zero
 * and should change only through App\Modules\Campaign\Services\Joins.
 *
 * The default quotation has a landed cost of 61.40 SAR, and the default
 * tiers clear the 15% margin floor at every quantity.
 *
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /** 1-50 at 120.00, 51-100 at 105.00, 101-200 at 95.00 SAR. */
    public const TIERS = [
        ['min_qty' => 1, 'max_qty' => 50, 'unit_price_halalas' => 12000],
        ['min_qty' => 51, 'max_qty' => 100, 'unit_price_halalas' => 10500],
        ['min_qty' => 101, 'max_qty' => 200, 'unit_price_halalas' => 9500],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('GB-2611-####'),
            'product_id' => Product::factory()->active(),
            'supplier_quotation_id' => fn (array $attributes) => SupplierQuotation::factory()
                ->for(Supplier::factory()->approved())
                ->accepted()
                ->create(['product_id' => $attributes['product_id']])
                ->id,
            'title_ar' => 'خلاط كهربائي - شراء جماعي',
            'title_en' => 'Electric blender group buy',
            'min_target_qty' => 50,
            'max_qty' => 200,
            'per_customer_max_qty' => null,
            'starts_at' => now(),
            'status' => 'draft',
            'delivery_mode' => 'warehouse',
            'created_by_admin_id' => AdminUser::factory(),
            'lock_version' => 1,
        ];
    }

    /**
     * Every variant of the product joins the campaign.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Campaign $campaign): void {
            $campaign->variants()->sync($campaign->product->variants()->pluck('id')->all());
        });
    }

    /**
     * Saves a tier set as version 1 (or the next version) and makes it current.
     *
     * @param  list<array{min_qty: int, max_qty: int, unit_price_halalas: int}>  $tiers
     */
    public function withTiers(array $tiers = self::TIERS): static
    {
        return $this->afterCreating(function (Campaign $campaign) use ($tiers): void {
            $version = ($campaign->current_tier_version ?? 0) + 1;

            foreach ($tiers as $tier) {
                CampaignPriceTier::create($tier + [
                    'campaign_id' => $campaign->id,
                    'version' => $version,
                    'currency' => 'SAR',
                    'created_by_admin_id' => $campaign->created_by_admin_id,
                    'reason' => $version > 1 ? 'Test change' : null,
                ]);
            }

            $campaign->forceFill(['current_tier_version' => $version])->saveQuietly();
        });
    }

    public function pendingApproval(): static
    {
        return $this->withTiers()->state(['status' => 'pending_approval']);
    }

    /**
     * Approved and waiting for its start.
     */
    public function scheduled(): static
    {
        return $this->approved('scheduled', now()->addDay());
    }

    /**
     * Approved and open for joins, ending in 7 days.
     */
    public function active(): static
    {
        return $this->approved('active', now());
    }

    private function approved(string $status, mixed $startsAt): static
    {
        return $this->withTiers()->state(fn (array $attributes) => [
            'status' => $status,
            'starts_at' => $startsAt,
            'deadline_at' => $startsAt->copy()->addDays(7),
            'landed_cost_per_unit_halalas' => 6140,
            'approved_by_admin_id' => AdminUser::factory(),
            'approved_at' => now(),
        ]);
    }
}
