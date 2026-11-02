<?php

namespace Tests\Feature\Campaign;

use App\Models\StatusTransition;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Identity\Models\Address;
use App\Modules\Payments\Models\PaymentAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCampaigns;
use Tests\TestCase;

class JoinCampaignTest extends TestCase
{
    use InteractsWithCampaigns, RefreshDatabase;

    private Campaign $campaign;

    private string $token;

    private Address $address;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->campaign = Campaign::factory()->active()->create();
        [, $this->token, $this->address] = $this->customerReadyToJoin();
    }

    #[Test]
    public function joining_reserves_units_and_returns_the_payment_form(): void
    {
        $input = $this->joinInput($this->campaign, $this->address, ['quantity' => 2]);

        $data = $this->withToken($this->token)->postJson($this->url(), $input)
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_payment')
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.hold_unit_price.amount_minor', 12000)
            ->assertJsonPath('data.hold_amount.amount_minor', 24000)
            ->assertJsonPath('data.reservation_expires_at', '2026-11-02T09:15:00Z')
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.campaign.progress.remaining_qty', 198)
            ->assertJsonPath('data.campaign.progress.committed_qty', 0)
            ->assertJsonPath('data.payment.status', 'pending_authorization')
            ->assertJsonPath('data.payment.expires_at', '2026-11-16T09:00:00Z')
            ->assertJsonPath('data.payment.checkout.amount', 24000)
            ->assertJsonPath('data.payment.checkout.manual', true)
            ->assertJsonPath('data.payment.checkout.description', $this->campaign->code.' x2')
            ->json('data');

        $paymentId = $data['payment']['id'];
        $this->assertSame("http://localhost:3000/payments/return?payment_id={$paymentId}", $data['payment']['checkout']['callback_url']);
        $this->assertSame(['payment_id' => $paymentId], $data['payment']['checkout']['metadata']);

        $this->campaign->refresh();
        $this->assertSame([0, 2], [$this->campaign->committed_qty, $this->campaign->reserved_qty]);

        $participation = CampaignParticipation::sole();
        $this->assertSame($this->address->id, $participation->delivery_address_id);
        $this->assertSame($this->address->street, $participation->delivery_address['street']);
        $this->assertSame(1, $participation->tier_version);
        $this->assertSame(2, StatusTransition::count());
    }

    #[Test]
    public function a_retried_join_returns_the_first_one(): void
    {
        $input = $this->joinInput($this->campaign, $this->address);

        $first = $this->withToken($this->token)->postJson($this->url(), $input)->assertCreated()->json('data.id');
        $again = $this->withToken($this->token)->postJson($this->url(), $input)->assertCreated()->json('data.id');

        $this->assertSame($first, $again);
        $this->assertSame(1, CampaignParticipation::count());
        $this->assertSame(1, $this->campaign->refresh()->reserved_qty);

        // The same request ID cannot start a join somewhere else.
        $other = Campaign::factory()->active()->create();
        $this->withToken($this->token)->postJson("/api/v1/campaigns/{$other->uuid}/participations", $this->joinInput($other, $this->address, ['client_request_id' => $input['client_request_id']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('client_request_id');
    }

    #[Test]
    public function the_customer_must_accept_the_privacy_policy_first(): void
    {
        [, $token] = $this->customerWithToken();

        $this->withToken($token)->postJson($this->url(), $this->joinInput($this->campaign, $this->address))
            ->assertForbidden()
            ->assertJsonPath('code', 'privacy_consent_required');
    }

    #[Test]
    public function the_variant_and_address_must_belong_to_the_campaign_and_the_customer(): void
    {
        $someoneElses = Address::factory()->create();
        $foreignVariant = Campaign::factory()->active()->create()->variants()->firstOrFail();

        $this->withToken($this->token)->postJson($this->url(), $this->joinInput($this->campaign, $this->address, ['product_variant_id' => $foreignVariant->uuid]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'variant_not_in_campaign')
            ->assertJsonValidationErrors('product_variant_id');

        $this->withToken($this->token)->postJson($this->url(), $this->joinInput($this->campaign, $this->address, ['delivery_address_id' => $someoneElses->uuid]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'address_not_found');

        $this->withToken($this->token)->postJson($this->url(), ['quantity' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_request_id', 'product_variant_id', 'quantity', 'shown_unit_price_halalas', 'delivery_address_id']);

        $this->assertSame(0, $this->campaign->refresh()->reserved_qty);
    }

    #[Test]
    public function a_variant_archived_after_approval_is_no_longer_sold(): void
    {
        $variant = $this->campaign->variants()->firstOrFail();
        $variant->forceFill(['status' => 'archived'])->save();

        $this->getJson("/api/v1/campaigns/{$this->campaign->uuid}")->assertOk()->assertJsonPath('data.variants', []);
        $this->withToken($this->token)->postJson($this->url(), $this->joinInput($this->campaign, $this->address, ['product_variant_id' => $variant->uuid]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'variant_not_in_campaign');

        $this->assertSame(0, $this->campaign->refresh()->reserved_qty);
    }

    #[Test]
    public function a_price_the_customer_did_not_see_is_never_held(): void
    {
        $this->withToken($this->token)->postJson($this->url(), $this->joinInput($this->campaign, $this->address, ['shown_unit_price_halalas' => 10500]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'price_changed')
            ->assertJsonPath('current_unit_price.amount_minor', 12000);

        $this->assertSame(0, $this->campaign->refresh()->reserved_qty);
        $this->assertSame(0, CampaignParticipation::count());
    }

    #[Test]
    public function the_hold_price_follows_the_units_already_paid_for(): void
    {
        // 50 authorized units: the 51st unit costs 105.00.
        [, $big, $bigAddress] = $this->customerReadyToJoin();
        $this->authorizeHold($this->joinAs($big, $this->campaign, $bigAddress, ['quantity' => 50]));

        $this->withToken($this->token)->postJson($this->url(), $this->joinInput($this->campaign, $this->address))
            ->assertStatus(409)
            ->assertJsonPath('current_unit_price.amount_minor', 10500);

        $this->withToken($this->token)->postJson($this->url(), $this->joinInput($this->campaign, $this->address, ['shown_unit_price_halalas' => 10500, 'quantity' => 3]))
            ->assertCreated()
            ->assertJsonPath('data.hold_amount.amount_minor', 31500);
    }

    #[Test]
    public function joins_stop_at_the_maximum(): void
    {
        $campaign = Campaign::factory()->active()->withTiers([['min_qty' => 1, 'max_qty' => 5, 'unit_price_halalas' => 12000]])->create(['min_target_qty' => 3, 'max_qty' => 5]);
        $url = "/api/v1/campaigns/{$campaign->uuid}/participations";

        $this->withToken($this->token)->postJson($url, $this->joinInput($campaign, $this->address, ['quantity' => 4]))->assertCreated();

        $this->withToken($this->token)->postJson($url, $this->joinInput($campaign, $this->address, ['quantity' => 2]))
            ->assertStatus(409)
            ->assertJsonPath('code', 'sold_out')
            ->assertJsonPath('remaining_qty', 1);

        $this->withToken($this->token)->postJson($url, $this->joinInput($campaign, $this->address))->assertCreated();
        $this->assertSame([0, 5], [$campaign->refresh()->committed_qty, $campaign->reserved_qty]);
    }

    #[Test]
    public function only_an_active_campaign_before_its_deadline_takes_joins(): void
    {
        $scheduled = Campaign::factory()->scheduled()->create();

        $this->withToken($this->token)->postJson("/api/v1/campaigns/{$scheduled->uuid}/participations", $this->joinInput($scheduled, $this->address))
            ->assertStatus(409)
            ->assertJsonPath('code', 'campaign_not_joinable');

        // Past the deadline but not yet closed by the scheduler.
        $this->travelTo($this->campaign->deadline_at);
        [, $token, $address] = $this->customerReadyToJoin();
        $this->forgetAuthenticatedUsers();
        $this->withToken($token)->postJson($this->url(), $this->joinInput($this->campaign, $address))
            ->assertStatus(409)
            ->assertJsonPath('code', 'campaign_not_joinable');

        // Customers cannot see a draft at all.
        $draft = Campaign::factory()->withTiers()->create();
        $this->withToken($token)->postJson("/api/v1/campaigns/{$draft->uuid}/participations", $this->joinInput($draft, $address))
            ->assertNotFound();
    }

    #[Test]
    public function a_customer_cannot_hold_more_than_the_per_customer_limit(): void
    {
        $this->campaign->forceFill(['per_customer_max_qty' => 3])->save();

        $this->withToken($this->token)->postJson($this->url(), $this->joinInput($this->campaign, $this->address, ['quantity' => 2]))->assertCreated();

        $this->withToken($this->token)->postJson($this->url(), $this->joinInput($this->campaign, $this->address, ['quantity' => 2]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'per_customer_limit_exceeded')
            ->assertJsonValidationErrors('quantity');

        // An expired reservation no longer counts against the limit.
        $this->travel(16)->minutes();
        $this->artisan('campaigns:tick')->assertSuccessful();

        $this->withToken($this->token)->postJson($this->url(), $this->joinInput($this->campaign, $this->address, ['quantity' => 3]))->assertCreated();
        $this->assertSame(3, $this->campaign->refresh()->reserved_qty);
    }

    #[Test]
    public function joining_needs_a_signed_in_customer(): void
    {
        $this->postJson($this->url(), $this->joinInput($this->campaign, $this->address))->assertUnauthorized();

        $this->postJson('/api/v1/campaigns/'.Str::uuid().'/participations', [])->assertUnauthorized();
    }

    #[Test]
    public function an_unpaid_reservation_frees_its_units_after_15_minutes(): void
    {
        $participation = $this->joinAs($this->token, $this->campaign, $this->address, ['quantity' => 4]);

        $this->travel(14)->minutes();
        $this->artisan('campaigns:tick')->assertSuccessful();
        $this->assertSame('pending_payment', $participation->refresh()->status->value);

        $this->travel(1)->minute();
        $this->artisan('campaigns:tick')->assertSuccessful();

        $this->assertSame(['reservation_expired', 'reservation_expired'], [$participation->refresh()->status->value, $participation->cancel_reason]);
        $this->assertSame(0, $this->campaign->refresh()->reserved_qty);

        $payment = PaymentAuthorization::sole();
        $this->assertSame(['failed', 'reservation_expired'], [$payment->status->value, $payment->rejection_reason->value]);
    }

    private function url(): string
    {
        return "/api/v1/campaigns/{$this->campaign->uuid}/participations";
    }
}
