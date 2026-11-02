<?php

namespace Tests\Feature\Campaign;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCampaigns;
use Tests\TestCase;

class BrowseCampaignsTest extends TestCase
{
    use InteractsWithCampaigns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
    }

    #[Test]
    public function customers_see_open_campaigns_soonest_deadline_first(): void
    {
        $later = Campaign::factory()->active()->create(['deadline_at' => now()->addDays(6)]);
        $sooner = Campaign::factory()->active()->create(['deadline_at' => now()->addDays(5)]);
        $upcoming = Campaign::factory()->scheduled()->create();
        Campaign::factory()->withTiers()->create();
        Campaign::factory()->pendingApproval()->create();
        Campaign::factory()->create(['status' => 'cancelled', 'end_reason' => 'Never approved']);

        $this->getJson('/api/v1/campaigns')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $sooner->uuid)
            ->assertJsonPath('data.1.id', $later->uuid)
            ->assertJsonPath('data.0.current_unit_price.amount_minor', 12000)
            ->assertJsonPath('data.0.lowest_unit_price.amount_minor', 9500)
            ->assertJsonPath('data.0.progress.units_to_target', 50)
            ->assertJsonPath('data.0.title', 'Electric blender group buy');

        $this->getJson('/api/v1/campaigns?filter=upcoming', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $upcoming->uuid)
            ->assertJsonPath('data.0.title', 'خلاط كهربائي - شراء جماعي');

        $this->getJson('/api/v1/campaigns?filter=ended')->assertOk()->assertJsonCount(0, 'data');

        $page = $this->getJson('/api/v1/campaigns?per_page=1')->assertJsonCount(1, 'data')->json('meta.next_cursor');
        $this->getJson('/api/v1/campaigns?per_page=1&cursor='.$page)
            ->assertJsonPath('data.0.id', $later->uuid)
            ->assertJsonPath('meta.next_cursor', null);

        $this->getJson('/api/v1/campaigns?filter=sideways')->assertStatus(422)->assertJsonValidationErrors('filter');
    }

    #[Test]
    public function the_list_can_be_narrowed_to_a_category(): void
    {
        $kitchen = Category::factory()->create(['slug' => 'kitchen']);
        $match = Campaign::factory()->active()->create(['product_id' => Product::factory()->active()->for($kitchen)]);
        Campaign::factory()->active()->create();

        $this->getJson('/api/v1/campaigns?category=kitchen')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->uuid);
    }

    #[Test]
    public function a_campaign_page_shows_its_tiers_and_variants(): void
    {
        $campaign = Campaign::factory()->active()->create(['per_customer_max_qty' => 4]);

        $this->getJson("/api/v1/campaigns/{$campaign->uuid}", ['Accept-Language' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.code', $campaign->code)
            ->assertJsonCount(3, 'data.tiers')
            ->assertJsonPath('data.tiers.0.is_current', true)
            ->assertJsonPath('data.tiers.1.is_current', false)
            ->assertJsonPath('data.variants.0.id', $campaign->variants()->value('uuid'))
            ->assertJsonPath('data.variants.0.name', 'Black')
            ->assertJsonPath('data.per_customer_max_qty', 4)
            ->assertJsonPath('data.cancellation_rule', 'before_deal_confirmed')
            ->assertJsonPath('data.deadline_at', '2026-11-09T09:00:00Z');

        $this->getJson("/api/v1/campaigns/{$campaign->uuid}/progress")
            ->assertOk()
            ->assertJsonPath('data.campaign_id', $campaign->uuid)
            ->assertJsonPath('data.remaining_qty', 200);
    }

    #[Test]
    public function drafts_are_not_found(): void
    {
        $draft = Campaign::factory()->withTiers()->create();

        $this->getJson("/api/v1/campaigns/{$draft->uuid}")->assertNotFound();
        $this->getJson("/api/v1/campaigns/{$draft->uuid}/progress")->assertNotFound();
        $this->getJson('/api/v1/campaigns/not-a-uuid')->assertNotFound();
    }
}
