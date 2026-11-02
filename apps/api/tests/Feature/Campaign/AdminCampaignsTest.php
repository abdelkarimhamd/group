<?php

namespace Tests\Feature\Campaign;

use App\Models\AuditLog;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignEvent;
use App\Modules\Campaign\Models\CampaignPriceTier;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierQuotation;
use App\Support\Phone\SaudiMobile;
use Carbon\CarbonImmutable;
use Database\Factories\CampaignFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCampaigns;
use Tests\TestCase;

class AdminCampaignsTest extends TestCase
{
    use InteractsWithCampaigns, RefreshDatabase;

    private AdminUser $manager;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        [$this->manager, $this->token] = $this->adminWithToken(AdminRole::CampaignManager);
    }

    #[Test]
    public function a_campaign_starts_as_a_draft_without_tiers(): void
    {
        $quotation = $this->quotation();

        $campaign = $this->withToken($this->token)->postJson('/api/v1/admin/campaigns', $this->input($quotation))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.code', 'GB-2611-0001')
            ->assertJsonPath('data.tiers', [])
            ->assertJsonPath('data.tier_version', null)
            ->assertJsonPath('data.deadline_at', null)
            ->assertJsonPath('data.lock_version', 1)
            ->assertJsonPath('data.landed_cost_per_unit.amount_minor', 6140)
            ->assertJsonPath('data.supplier.moq', 100)
            ->assertJsonPath('data.available_actions', ['edit', 'edit_tiers', 'submit', 'cancel'])
            ->json('data');

        $model = Campaign::query()->where('uuid', $campaign['id'])->sole();
        $this->assertSame(['created'], $model->events()->pluck('event_type')->all());
        $this->assertSame([$quotation->product->variants()->value('uuid')], AuditLog::where('action', 'campaign.create')->sole()->after['product_variant_ids']);

        $this->withToken($this->token)->postJson('/api/v1/admin/campaigns', $this->input($quotation))
            ->assertJsonPath('data.code', 'GB-2611-0002');
    }

    #[Test]
    public function a_draft_must_use_a_quote_that_can_price_it(): void
    {
        $quotation = $this->quotation();
        $other = $this->quotation();
        $variant = $quotation->product->variants()->firstOrFail();
        $second = ProductVariant::factory()->for($quotation->product)->create();

        $cases = [
            'quotation_not_for_product' => $this->input($quotation, ['product_id' => $other->product->uuid, 'product_variant_ids' => [$other->product->variants()->value('uuid')]]),
            'variant_not_in_product' => $this->input($quotation, ['product_variant_ids' => [$other->product->variants()->value('uuid')]]),
        ];

        foreach ($cases as $code => $input) {
            $this->withToken($this->token)->postJson('/api/v1/admin/campaigns', $input)
                ->assertStatus(422)
                ->assertJsonPath('code', $code);
        }

        $quotation->forceFill(['product_variant_id' => $variant->id])->save();
        $this->withToken($this->token)->postJson('/api/v1/admin/campaigns', $this->input($quotation, ['product_variant_ids' => [$variant->uuid, $second->uuid]]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'quotation_not_for_variants')
            ->assertJsonValidationErrors('product_variant_ids');

        $quotation->forceFill(['status' => 'expired'])->save();
        $this->withToken($this->token)->postJson('/api/v1/admin/campaigns', $this->input($quotation, ['product_variant_ids' => [$variant->uuid]]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'quotation_not_usable');

        $blocked = $this->quotation(Supplier::factory()->blocked()->create());
        $this->withToken($this->token)->postJson('/api/v1/admin/campaigns', $this->input($blocked))
            ->assertStatus(422)
            ->assertJsonPath('code', 'supplier_blocked');

        $this->withToken($this->token)->postJson('/api/v1/admin/campaigns', $this->input($other, ['min_target_qty' => 300, 'per_customer_max_qty' => 500]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_qty', 'per_customer_max_qty']);

        $this->assertSame(0, Campaign::count());
    }

    #[Test]
    public function a_draft_edit_replaces_its_terms_and_needs_the_current_lock_version(): void
    {
        $campaign = Campaign::factory()->create(['per_customer_max_qty' => 5]);
        $input = $this->input($campaign->quotation, ['title_en' => 'Blender, second try', 'lock_version' => 1]);
        unset($input['per_customer_max_qty']);

        $this->withToken($this->token)->patchJson("/api/v1/admin/campaigns/{$campaign->uuid}", $input)
            ->assertOk()
            ->assertJsonPath('data.title_en', 'Blender, second try')
            ->assertJsonPath('data.per_customer_max_qty', null)
            ->assertJsonPath('data.lock_version', 2);

        $audit = AuditLog::where('action', 'campaign.update')->sole();
        $this->assertSame(['title_en' => 'Electric blender group buy', 'per_customer_max_qty' => 5], array_intersect_key($audit->before, ['title_en' => 1, 'per_customer_max_qty' => 1]));

        // Someone else's edit made lock_version 1 stale.
        $this->withToken($this->token)->patchJson("/api/v1/admin/campaigns/{$campaign->uuid}", $input)
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_campaign');

        // Saving the same terms again changes nothing.
        $this->withToken($this->token)->patchJson("/api/v1/admin/campaigns/{$campaign->uuid}", ['lock_version' => 2] + $input)
            ->assertOk()
            ->assertJsonPath('data.lock_version', 2);
        $this->assertSame(1, AuditLog::where('action', 'campaign.update')->count());

        $campaign->forceFill(['status' => 'pending_approval'])->save();
        $this->withToken($this->token)->patchJson("/api/v1/admin/campaigns/{$campaign->uuid}", ['lock_version' => 2] + $input)
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition');
    }

    #[Test]
    public function each_tier_save_is_a_new_version_and_old_versions_are_kept(): void
    {
        $campaign = Campaign::factory()->create();
        $url = "/api/v1/admin/campaigns/{$campaign->uuid}/tiers";

        $this->withToken($this->token)->putJson($url, ['lock_version' => 1, 'tiers' => CampaignFactory::TIERS])
            ->assertOk()
            ->assertJsonPath('data.tier_version', 1)
            ->assertJsonPath('data.lock_version', 2)
            ->assertJsonPath('data.tiers.0.unit_price', ['amount_minor' => 12000, 'currency' => 'SAR', 'formatted' => '120.00 SAR'])
            ->assertJsonPath('data.tiers.2.min_qty', 101);

        $cheaper = CampaignFactory::TIERS;
        $cheaper[2]['unit_price_halalas'] = 9000;

        $this->withToken($this->token)->putJson($url, ['lock_version' => 2, 'tiers' => $cheaper])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->withToken($this->token)->putJson($url, ['lock_version' => 2, 'tiers' => $cheaper, 'reason' => 'Supplier lowered the bulk price'])
            ->assertOk()
            ->assertJsonPath('data.tier_version', 2)
            ->assertJsonPath('data.tiers.2.unit_price.amount_minor', 9000);

        $this->assertSame([1 => 3, 2 => 3], CampaignPriceTier::where('campaign_id', $campaign->id)->get()->countBy('version')->all());
        $this->assertSame(['tiers_changed', 'tiers_changed'], $campaign->events()->orderBy('id')->pluck('event_type')->all());
        $this->assertSame(9500, AuditLog::where('action', 'campaign.tiers')->latest('id')->first()->before['tiers'][2]['unit_price_halalas']);

        $this->withToken($this->token)->putJson($url, ['lock_version' => 1, 'tiers' => CampaignFactory::TIERS, 'reason' => 'Old tab'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'stale_campaign');
    }

    #[Test]
    public function tiers_must_cover_the_campaign_exactly_and_never_get_dearer(): void
    {
        $campaign = Campaign::factory()->create();
        $url = "/api/v1/admin/campaigns/{$campaign->uuid}/tiers";
        $tier = fn (int $min, int $max, int $price) => ['min_qty' => $min, 'max_qty' => $max, 'unit_price_halalas' => $price];

        $cases = [
            'tiers_gap' => [$tier(1, 50, 12000), $tier(52, 200, 10000)],
            'tiers_overlap' => [$tier(1, 50, 12000), $tier(50, 200, 10000)],
            'tiers_do_not_reach_max' => [$tier(1, 50, 12000), $tier(51, 150, 10000)],
            'tiers_exceed_max' => [$tier(1, 50, 12000), $tier(51, 250, 10000)],
            'price_rises_with_quantity' => [$tier(1, 50, 10000), $tier(51, 200, 12000)],
        ];

        foreach ($cases as $code => $tiers) {
            $this->withToken($this->token)->putJson($url, ['lock_version' => 1, 'tiers' => $tiers])
                ->assertStatus(422)
                ->assertJsonPath('code', $code)
                ->assertJsonValidationErrors('tiers');
        }

        $this->withToken($this->token)->putJson($url, ['lock_version' => 1, 'tiers' => [$tier(1, 200, 12000), ['min_qty' => 5, 'max_qty' => 2, 'unit_price_halalas' => 0]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tiers.1.max_qty', 'tiers.1.unit_price_halalas']);

        // Starting above 1 is a gap too.
        $this->withToken($this->token)->putJson($url, ['lock_version' => 1, 'tiers' => [$tier(2, 200, 12000)]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'tiers_gap');

        $this->assertSame(0, CampaignPriceTier::count());
    }

    #[Test]
    public function the_margin_report_checks_every_tier_against_the_floor(): void
    {
        $campaign = Campaign::factory()->create();

        $this->withToken($this->token)->getJson("/api/v1/admin/campaigns/{$campaign->uuid}/margin")
            ->assertStatus(409)
            ->assertJsonPath('code', 'tiers_missing');

        $campaign = Campaign::factory()->withTiers()->create();

        // Fee: 2.75% of 120.00 rounded up, plus 1.00 = 4.30 a unit.
        $this->withToken($this->token)->getJson("/api/v1/admin/campaigns/{$campaign->uuid}/margin")
            ->assertOk()
            ->assertJsonPath('data.min_margin_bp', 1500)
            ->assertJsonPath('data.passes', true)
            ->assertJsonPath('data.landed_cost_per_unit.amount_minor', 6140)
            ->assertJsonPath('data.payment_fee_estimate.amount_minor', 430)
            ->assertJsonPath('data.delivery_cost_estimate.amount_minor', 0)
            ->assertJsonPath('data.tiers.0.net_price.amount_minor', 10435)
            ->assertJsonPath('data.tiers.0.margin.amount_minor', 3865)
            ->assertJsonPath('data.tiers.0.margin_bp', 3703)
            ->assertJsonPath('data.tiers.2.net_price.amount_minor', 8261)
            ->assertJsonPath('data.tiers.2.margin_bp', 2046)
            ->assertJsonPath('data.warnings', ['min_target_below_supplier_moq']);

        $thin = CampaignFactory::TIERS;
        $thin[2]['unit_price_halalas'] = 8000;
        $campaign = Campaign::factory()->withTiers($thin)->create(['min_target_qty' => 150]);
        $campaign->quotation->forceFill(['valid_until' => '2026-11-10'])->save();

        $this->withToken($this->token)->getJson("/api/v1/admin/campaigns/{$campaign->uuid}/margin")
            ->assertOk()
            ->assertJsonPath('data.passes', false)
            ->assertJsonPath('data.tiers.1.passes', true)
            ->assertJsonPath('data.tiers.2.passes', false)
            ->assertJsonPath('data.tiers.2.margin_bp', 556)
            ->assertJsonPath('data.warnings', ['target_at_lowest_tier_boundary', 'quotation_expires_before_capture']);
    }

    #[Test]
    public function submitting_needs_tiers_a_landed_cost_and_an_approved_supplier(): void
    {
        $campaign = Campaign::factory()->create();
        $submit = fn (Campaign $campaign) => $this->withToken($this->token)->postJson("/api/v1/admin/campaigns/{$campaign->uuid}/submit");

        $submit($campaign)->assertStatus(422)->assertJsonPath('code', 'tiers_missing');

        $campaign = Campaign::factory()->withTiers()->create();
        $campaign->quotation->forceFill(['landed_cost_per_unit_halalas' => 0])->save();
        $submit($campaign)->assertStatus(422)->assertJsonPath('code', 'landed_cost_missing');

        $campaign = Campaign::factory()->withTiers()->create();
        $campaign->quotation->supplier->forceFill(['status' => 'prospect'])->save();
        $submit($campaign)->assertStatus(422)->assertJsonPath('code', 'supplier_not_approved');

        $campaign = Campaign::factory()->withTiers()->create();
        $campaign->product->forceFill(['status' => 'archived'])->save();
        $submit($campaign)->assertStatus(422)->assertJsonPath('code', 'product_not_active');

        $campaign = Campaign::factory()->withTiers()->create();
        $submit($campaign)
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.available_actions', ['cancel']);
        $submit($campaign)->assertStatus(409)->assertJsonPath('code', 'invalid_transition');

        $this->assertSame(['status' => 'pending_approval'], AuditLog::where('action', 'campaign.submit')->sole()->after);
    }

    #[Test]
    public function approval_locks_the_terms_and_sets_the_deadline(): void
    {
        $campaign = Campaign::factory()->pendingApproval()->create(['starts_at' => '2026-11-01 06:00:00']);
        [, $finance] = $this->adminWithToken(AdminRole::Finance);

        $this->withToken($this->token)->postJson("/api/v1/admin/campaigns/{$campaign->uuid}/approve")
            ->assertForbidden();

        $this->forgetAuthenticatedUsers();
        $this->withToken($finance)->postJson("/api/v1/admin/campaigns/{$campaign->uuid}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled')
            // A start in the past moves to the approval time.
            ->assertJsonPath('data.starts_at', '2026-11-02T09:00:00Z')
            ->assertJsonPath('data.deadline_at', '2026-11-09T09:00:00Z')
            ->assertJsonPath('data.approved_at', '2026-11-02T09:00:00Z')
            ->assertJsonPath('data.available_actions', []);

        $campaign->refresh();
        $this->assertSame(6140, $campaign->landed_cost_per_unit_halalas);
        $this->assertNull($campaign->margin_override_reason);

        // A later change to the quote does not move the snapshot.
        $campaign->quotation->forceFill(['landed_cost_per_unit_halalas' => 9000])->save();
        $this->forgetAuthenticatedUsers();
        $this->withToken($this->token)->getJson("/api/v1/admin/campaigns/{$campaign->uuid}/margin")
            ->assertJsonPath('data.landed_cost_per_unit.amount_minor', 6140);

        $price = CampaignEvent::where('event_type', 'price_changed')->sole();
        $this->assertEquals(['from_unit_price_halalas' => null, 'to_unit_price_halalas' => 12000, 'tier' => ['min_qty' => 1, 'max_qty' => 50], 'tier_version' => 1], $price->payload);

        $audit = AuditLog::where('action', 'campaign.approve')->sole();
        $this->assertSame('scheduled', $audit->after['status']);
        $this->assertTrue($audit->after['margin']['passes']);
    }

    #[Test]
    public function approving_below_the_margin_floor_needs_the_override_permission_and_a_reason(): void
    {
        $thin = CampaignFactory::TIERS;
        $thin[2]['unit_price_halalas'] = 8000;
        $campaign = Campaign::factory()->withTiers($thin)->create(['status' => 'pending_approval']);
        [, $finance] = $this->adminWithToken(AdminRole::Finance);
        [, $super] = $this->adminWithToken(AdminRole::SuperAdmin);
        $url = "/api/v1/admin/campaigns/{$campaign->uuid}/approve";

        $this->withToken($finance)->postJson($url)
            ->assertStatus(422)
            ->assertJsonPath('code', 'margin_below_floor')
            ->assertJsonPath('margin.passes', false)
            ->assertJsonPath('margin.tiers.2.margin_bp', 556);

        $this->withToken($finance)->postJson($url, ['override_reason' => 'Launch promotion'])
            ->assertForbidden();

        $this->forgetAuthenticatedUsers();
        $this->withToken($super)->postJson($url, ['override_reason' => 'Launch promotion'])
            ->assertOk()
            ->assertJsonPath('data.status', 'scheduled');

        $this->assertSame('Launch promotion', $campaign->refresh()->margin_override_reason);
        $this->assertSame('Launch promotion', AuditLog::where('action', 'campaign.approve')->sole()->after['margin_override_reason']);
    }

    #[Test]
    public function rejecting_sends_the_campaign_back_to_draft_with_the_reason(): void
    {
        $campaign = Campaign::factory()->pendingApproval()->create();
        [, $finance] = $this->adminWithToken(AdminRole::Finance);

        $this->withToken($finance)->postJson("/api/v1/admin/campaigns/{$campaign->uuid}/reject", ['reason' => 'Target too low for this supplier'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $event = CampaignEvent::where('event_type', 'rejected')->sole();
        $this->assertSame(['reason' => 'Target too low for this supplier'], $event->payload);
        $this->assertSame(['pending_approval', 'draft'], [$event->from_status->value, $event->to_status->value]);
    }

    #[Test]
    public function the_scheduler_opens_campaigns_at_their_start_and_closes_them_at_the_deadline(): void
    {
        $campaign = Campaign::factory()->scheduled()->create();

        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->assertSame('scheduled', $campaign->refresh()->status->value);

        $this->travel(1)->days();
        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->assertSame('active', $campaign->refresh()->status->value);

        // Closing starts at the deadline; with no joins the closing job then fails it below target.
        $this->travelTo($campaign->deadline_at);
        $this->artisan('campaigns:tick')->assertSuccessful();
        $campaign->refresh();
        $this->assertSame(['failed', 'deadline', 'target_missed'], [$campaign->status->value, $campaign->close_reason->value, $campaign->end_reason]);
        $this->assertSame(['published', 'closed', 'failed'], $campaign->events()->whereIn('event_type', ['published', 'closed', 'failed'])->orderBy('id')->pluck('event_type')->all());
        $this->assertSame(['active', 'closing'], [$campaign->events()->where('event_type', 'closed')->sole()->from_status->value, $campaign->events()->where('event_type', 'closed')->sole()->to_status->value]);
    }

    #[Test]
    public function the_deadline_can_be_extended_once_and_never_into_the_last_days_of_a_hold(): void
    {
        $campaign = Campaign::factory()->active()->create();
        $url = "/api/v1/admin/campaigns/{$campaign->uuid}";

        // No holds yet: 14 days from now, less the 3-day buffer.
        $this->withToken($this->token)->getJson("{$url}/extension-window")
            ->assertOk()
            ->assertJsonPath('data', [
                'allowed' => true,
                'blocked_reason' => null,
                'latest_allowed_deadline_at' => '2026-11-13T09:00:00Z',
                'earliest_hold_expires_at' => null,
            ]);

        // A join now holds a card until 16 Nov 09:00; a day later that hold, less
        // the buffer, is the limit.
        [, $customer, $address] = $this->customerReadyToJoin();
        $this->joinAs($customer, $campaign, $address);
        $this->travel(1)->day();
        [, $this->token] = $this->adminWithToken(AdminRole::CampaignManager);

        $this->withToken($this->token)->postJson("{$url}/extend", ['new_deadline_at' => '2026-11-13T10:00:00Z', 'reason' => 'Slow first week'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'extension_not_allowed')
            ->assertJsonValidationErrors('new_deadline_at')
            ->assertJsonPath('window.latest_allowed_deadline_at', '2026-11-13T09:00:00Z')
            ->assertJsonPath('window.earliest_hold_expires_at', '2026-11-16T09:00:00Z');

        $this->withToken($this->token)->postJson("{$url}/extend", ['new_deadline_at' => '2026-11-08T09:00:00Z', 'reason' => 'Earlier is not an extension'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'extension_not_allowed');

        $this->withToken($this->token)->postJson("{$url}/extend", ['new_deadline_at' => '2026-11-12T09:00:00+03:00', 'reason' => 'Slow first week'])
            ->assertOk()
            ->assertJsonPath('data.deadline_at', '2026-11-12T06:00:00Z')
            ->assertJsonPath('data.original_deadline_at', '2026-11-09T09:00:00Z')
            ->assertJsonPath('data.extended_at', '2026-11-03T09:00:00Z')
            ->assertJsonPath('data.extension_reason', 'Slow first week')
            ->assertJsonPath('data.available_actions', ['cancel']);

        $this->withToken($this->token)->getJson("{$url}/extension-window")
            ->assertJsonPath('data.allowed', false)
            ->assertJsonPath('data.blocked_reason', 'already_extended');

        $this->withToken($this->token)->postJson("{$url}/extend", ['new_deadline_at' => '2026-11-12T08:00:00Z', 'reason' => 'Again'])
            ->assertStatus(422)
            ->assertJsonPath('window.blocked_reason', 'already_extended');

        $event = CampaignEvent::where('event_type', 'extended')->sole();
        $this->assertEquals(['from_deadline_at' => '2026-11-09T09:00:00Z', 'deadline_at' => '2026-11-12T06:00:00Z', 'reason' => 'Slow first week'], $event->payload);
    }

    #[Test]
    public function a_confirmed_deal_cannot_be_extended(): void
    {
        $campaign = Campaign::factory()->active()->create(['min_target_qty' => 1]);
        [, $customer, $address] = $this->customerReadyToJoin();
        $this->authorizeHold($this->joinAs($customer, $campaign, $address));

        $this->withToken($this->token)->getJson("/api/v1/admin/campaigns/{$campaign->uuid}/extension-window")
            ->assertJsonPath('data.allowed', false)
            ->assertJsonPath('data.blocked_reason', 'deal_confirmed');
    }

    #[Test]
    public function cancelling_releases_every_join_and_voids_every_hold(): void
    {
        $campaign = Campaign::factory()->active()->create();
        [, $first, $firstAddress] = $this->customerReadyToJoin();
        [, $second, $secondAddress] = $this->customerReadyToJoin();
        $waiting = $this->joinAs($first, $campaign, $firstAddress, ['quantity' => 2]);
        $held = $this->joinAs($second, $campaign, $secondAddress);
        $this->authorizeHold($held);

        $this->withToken($this->token)->postJson("/api/v1/admin/campaigns/{$campaign->uuid}/cancel", ['reason' => 'Supplier stopped making it'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.end_reason', 'Supplier stopped making it')
            ->assertJsonPath('data.reserved_qty', 0)
            ->assertJsonPath('data.committed_qty', 1)
            ->assertJsonPath('data.available_actions', []);

        $this->assertSame(['released', 'released'], [$waiting->refresh()->status->value, $held->refresh()->status->value]);
        $this->assertSame(['failed', 'campaign_closed'], [$waiting->latestPayment->status->value, $waiting->latestPayment->rejection_reason->value]);

        // The authorized hold was voided at Moyasar after commit, with its own key.
        $payment = $held->latestPayment;
        $this->assertSame('voided', $payment->status->value);
        $void = $payment->transactions()->sole();
        $this->assertSame(['void', 'succeeded', 'void:'.$payment->uuid], [$void->type->value, $void->status->value, $void->idempotency_key]);

        $event = CampaignEvent::where('event_type', 'cancelled')->sole();
        $this->assertEquals(['reason' => 'Supplier stopped making it', 'released_participations' => 2], $event->payload);

        $this->withToken($this->token)->postJson("/api/v1/admin/campaigns/{$campaign->uuid}/cancel", ['reason' => 'Twice'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition');
    }

    #[Test]
    public function once_captures_start_a_campaign_cannot_simply_be_cancelled(): void
    {
        $campaign = Campaign::factory()->active()->create();
        $campaign->forceFill(['status' => 'fulfilling', 'closing_step' => 'done', 'close_reason' => 'deadline', 'closed_at' => now()])->save();

        $this->withToken($this->token)->postJson("/api/v1/admin/campaigns/{$campaign->uuid}/cancel", ['reason' => 'Too late'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition');

        // While capturing, the cancellation waits for a finance approval (CancellingAfterCaptureTest).
        $campaign->forceFill(['status' => 'closing', 'closing_step' => 'awaiting_supplier'])->save();
        $this->withToken($this->token)->postJson("/api/v1/admin/campaigns/{$campaign->uuid}/cancel", ['reason' => 'Supplier said no'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    #[Test]
    public function the_list_can_show_only_campaigns_that_need_an_admin(): void
    {
        $waiting = Campaign::factory()->pendingApproval()->create();
        $stuck = Campaign::factory()->active()->create();
        $stuck->forceFill(['status' => 'closing', 'closing_step' => 'needs_admin', 'close_reason' => 'deadline', 'closed_at' => now()])->save();
        Campaign::factory()->active()->create();
        Campaign::factory()->create();

        $this->withToken($this->token)->getJson('/api/v1/admin/campaigns?needs_attention=true')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $stuck->uuid)
            ->assertJsonPath('data.0.available_actions', ['cancel', 'decide_below_target'])
            ->assertJsonPath('data.1.id', $waiting->uuid);

        $this->withToken($this->token)->getJson('/api/v1/admin/campaigns?status=draft&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.next_cursor', null);

        $this->withToken($this->token)->getJson('/api/v1/admin/campaigns?per_page=2')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2);
    }

    #[Test]
    public function participations_and_history_are_listed_for_admins(): void
    {
        $campaign = Campaign::factory()->active()->create();
        [$user, $customer, $address] = $this->customerReadyToJoin();
        $participation = $this->joinAs($customer, $campaign, $address, ['quantity' => 3]);
        $this->authorizeHold($participation);
        [, $support] = $this->adminWithToken(AdminRole::Support);

        $this->withToken($support)->getJson("/api/v1/admin/campaigns/{$campaign->uuid}/participations")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $participation->uuid)
            ->assertJsonPath('data.0.status', 'authorized')
            ->assertJsonPath('data.0.quantity', 3)
            ->assertJsonPath('data.0.hold_amount.amount_minor', 36000)
            ->assertJsonPath('data.0.customer.phone', SaudiMobile::mask($user->phone_e164))
            ->assertJsonPath('data.0.payment.status', 'authorized')
            ->assertJsonPath('data.0.payment.transactions', []);

        $this->withToken($support)->getJson("/api/v1/admin/campaigns/{$campaign->uuid}/participations?status=cancelled")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $types = collect($this->withToken($support)->getJson("/api/v1/admin/campaigns/{$campaign->uuid}/events")
            ->assertOk()
            ->json('data'))->pluck('event_type')->all();
        $this->assertSame(['joined'], $types);

        $this->withToken($support)->postJson('/api/v1/admin/campaigns', [])->assertForbidden();
        $this->withToken($support)->postJson("/api/v1/admin/campaigns/{$campaign->uuid}/cancel", ['reason' => 'No'])->assertForbidden();
    }

    #[Test]
    public function archived_variants_cannot_be_put_on_sale(): void
    {
        $quotation = $this->quotation();
        $archived = ProductVariant::factory()->archived()->for($quotation->product)->create();

        $this->withToken($this->token)->postJson('/api/v1/admin/campaigns', $this->input($quotation, ['product_variant_ids' => [$archived->uuid]]))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'variant_not_active');

        // A variant archived after the draft was made stops it going to review.
        $campaign = Campaign::factory()->withTiers()->create();
        $campaign->variants()->firstOrFail()->forceFill(['status' => 'archived'])->save();

        $this->withToken($this->token)->postJson("/api/v1/admin/campaigns/{$campaign->uuid}/submit")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'variant_not_active');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(SupplierQuotation $quotation, array $overrides = []): array
    {
        return $overrides + [
            'product_id' => $quotation->product->uuid,
            'supplier_quotation_id' => $quotation->uuid,
            'title_ar' => 'خلاط كهربائي - شراء جماعي',
            'title_en' => 'Electric blender group buy',
            'product_variant_ids' => $quotation->product->variants()->pluck('uuid')->all(),
            'min_target_qty' => 50,
            'max_qty' => 200,
            'per_customer_max_qty' => 5,
            'starts_at' => '2026-11-03T06:00:00Z',
        ];
    }

    private function quotation(?Supplier $supplier = null): SupplierQuotation
    {
        return SupplierQuotation::factory()
            ->for($supplier ?? Supplier::factory()->approved()->create())
            ->for(Product::factory()->active())
            ->accepted()
            ->create();
    }
}
