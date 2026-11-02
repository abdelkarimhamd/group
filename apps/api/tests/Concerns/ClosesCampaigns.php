<?php

namespace Tests\Concerns;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Campaign\Services\Closing;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierQuotation;
use Illuminate\Support\Facades\DB;

/**
 * Campaigns taken through closing with the fake Moyasar, for tests of what
 * comes after a capture (orders, invoices, refunds).
 */
trait ClosesCampaigns
{
    use InteractsWithCampaigns;

    /**
     * Active now, deadline in 7 days. 120.00 SAR up to 3 units and 105.00
     * from 4; the target and the supplier's MOQ are 3.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function closableCampaign(array $attributes = []): Campaign
    {
        return Campaign::factory()->active()
            ->withTiers([
                ['min_qty' => 1, 'max_qty' => 3, 'unit_price_halalas' => 12000],
                ['min_qty' => 4, 'max_qty' => 10, 'unit_price_halalas' => 10500],
            ])
            ->create($attributes + [
                'min_target_qty' => 3,
                'max_qty' => 10,
                'supplier_quotation_id' => fn (array $attributes) => SupplierQuotation::factory()
                    ->for(Supplier::factory()->approved())
                    ->accepted()
                    ->create(['product_id' => $attributes['product_id'], 'moq' => 3])
                    ->id,
            ]);
    }

    /**
     * A customer joins at the price shown now and their hold goes through.
     *
     * @return array{CampaignParticipation, string} The join and the customer's token.
     */
    protected function paidJoin(Campaign $campaign, int $qty, ?string $token = null, mixed $address = null): array
    {
        if ($token === null) {
            [, $token, $address] = $this->customerReadyToJoin();
        }

        $campaign->refresh();
        $price = $campaign->tierSet()->current($campaign->committed_qty)->unitPriceHalalas;
        $participation = $this->joinAs($token, $campaign, $address, ['quantity' => $qty, 'shown_unit_price_halalas' => $price]);
        $this->assertTrue($this->authorizeHold($participation));

        return [$participation->refresh(), $token];
    }

    /**
     * Past the deadline, the supplier confirms and every hold is captured.
     */
    protected function captureAll(Campaign $campaign): void
    {
        $this->travelTo($campaign->deadline_at);
        $this->artisan('campaigns:tick')->assertSuccessful();

        [$admin] = $this->adminWithToken(AdminRole::CampaignManager);
        app(Closing::class)->recordSupplierDecision($campaign->uuid, [
            'decision' => 'confirmed',
            'unit_cost_minor' => 4000,
            'currency' => 'USD',
            'available_qty' => (int) $campaign->refresh()->final_qty,
        ], $admin);

        // The captures ran after that commit (the test queue is sync).
        $this->assertSame(0, (int) DB::table('campaign_participations')->where('campaign_id', $campaign->id)->where('status', 'authorized')->count());
    }
}
