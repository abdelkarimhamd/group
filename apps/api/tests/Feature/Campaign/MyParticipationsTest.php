<?php

namespace Tests\Feature\Campaign;

use App\Models\StatusTransition;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ClosesCampaigns;
use Tests\TestCase;

/**
 * A customer's own joins: the list, one join, and cancelling before the
 * deal is confirmed (proposed rule, Q16).
 */
class MyParticipationsTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        // A target of 8, so the deal is not confirmed by a few joins.
        $this->campaign = $this->closableCampaign(['min_target_qty' => 8]);
    }

    #[Test]
    public function a_customer_lists_their_own_joins_newest_first(): void
    {
        [$paid, $token] = $this->paidJoin($this->campaign, 2);
        $waiting = $this->joinAs($token, $this->campaign, $paid->address, ['quantity' => 1, 'shown_unit_price_halalas' => 12000]);
        [$someoneElses] = $this->paidJoin($this->campaign, 1);

        $this->as($token, 'get', '/api/v1/me/participations')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $waiting->uuid)
            ->assertJsonPath('data.0.status', 'pending_payment')
            ->assertJsonPath('data.1.id', $paid->uuid)
            ->assertJsonPath('data.1.can_cancel', true)
            ->assertJsonPath('data.1.order_id', null);

        $this->as($token, 'get', '/api/v1/me/participations?status=authorized&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $paid->uuid)
            ->assertJsonPath('meta.next_cursor', null);

        $this->as($token, 'get', "/api/v1/me/participations/{$paid->uuid}")
            ->assertOk()
            ->assertJsonPath('data.quantity', 2)
            ->assertJsonPath('data.payment.status', 'authorized');

        $this->as($token, 'get', "/api/v1/me/participations/{$someoneElses->uuid}")->assertNotFound();
        $this->as($token, 'post', "/api/v1/me/participations/{$someoneElses->uuid}/cancel")->assertNotFound();
        $this->as($token, 'get', '/api/v1/me/participations?status=nope')->assertUnprocessable();
    }

    #[Test]
    public function a_join_still_waiting_for_payment_can_be_cancelled(): void
    {
        [, $token, $address] = $this->customerReadyToJoin();
        $join = $this->joinAs($token, $this->campaign, $address, ['quantity' => 2]);
        $this->assertSame(2, $this->campaign->refresh()->reserved_qty);

        $this->as($token, 'post', "/api/v1/me/participations/{$join->uuid}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.can_cancel', false)
            ->assertJsonPath('data.payment.status', 'failed')
            ->assertJsonPath('data.payment.rejection_reason', 'participation_cancelled');

        $this->assertSame([0, 0], [$this->campaign->refresh()->reserved_qty, $this->campaign->committed_qty]);
        $this->assertSame('customer_cancelled', $join->refresh()->cancel_reason);
        $event = CampaignEvent::query()->where('event_type', 'join_cancelled')->sole();
        $this->assertSame([$join->uuid, 2, 'pending_payment'], [$event->payload['participation_id'], $event->payload['quantity'], $event->payload['was']]);

        // Once cancelled, it stays cancelled.
        $this->as($token, 'post', "/api/v1/me/participations/{$join->uuid}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('code', 'cancellation_not_allowed');
    }

    #[Test]
    public function a_paid_join_can_be_cancelled_before_the_deal_is_confirmed(): void
    {
        // The third unit moves the price for the next joiner to the 105.00 tier.
        $this->paidJoin($this->campaign, 2);
        [$join, $token] = $this->paidJoin($this->campaign, 1);
        $this->assertSame([3, 12000], [$this->campaign->refresh()->committed_qty, $join->hold_unit_price_halalas]);

        $this->as($token, 'post', "/api/v1/me/participations/{$join->uuid}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment.status', 'voided')
            ->assertJsonPath('data.payment.rejection_reason', 'participation_cancelled');

        // The unit is back on sale, and the next joiner's price goes back up.
        $this->assertSame([2, 0], [$this->campaign->refresh()->committed_qty, $this->campaign->reserved_qty]);
        $this->assertSame(['authorized', 'cancelled'], StatusTransition::query()
            ->where('subject_type', 'campaign_participation')->where('subject_id', $join->id)->orderBy('id')->pluck('to_status')->slice(-2)->values()->all());
        $change = CampaignEvent::query()->where('event_type', 'price_changed')->latest('id')->firstOrFail();
        $this->assertSame([10500, 12000], [$change->payload['from_unit_price_halalas'], $change->payload['to_unit_price_halalas']]);
        $this->assertSame(2, CampaignEvent::query()->where('event_type', 'join_cancelled')->sole()->committed_qty_after);
    }

    #[Test]
    public function once_the_deal_is_confirmed_a_join_cannot_be_cancelled(): void
    {
        [$join, $token] = $this->paidJoin($this->campaign, 3);
        $this->paidJoin($this->campaign, 5);
        $this->assertNotNull($this->campaign->refresh()->deal_confirmed_at);

        $this->as($token, 'get', "/api/v1/me/participations/{$join->uuid}")->assertJsonPath('data.can_cancel', false);
        $this->as($token, 'post', "/api/v1/me/participations/{$join->uuid}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('code', 'cancellation_not_allowed');

        $this->assertSame(['authorized', 8], [$join->refresh()->status->value, $this->campaign->refresh()->committed_qty]);
    }

    #[Test]
    public function nothing_can_be_cancelled_once_the_deadline_passes_even_before_the_campaign_closes(): void
    {
        [$join, $token] = $this->paidJoin($this->campaign, 2);
        $this->travelTo($this->campaign->deadline_at->addSeconds(20));
        $this->assertSame('active', $this->campaign->refresh()->status->value);

        $this->as($token, 'get', "/api/v1/me/participations/{$join->uuid}")->assertJsonPath('data.can_cancel', false);
        $this->as($token, 'post', "/api/v1/me/participations/{$join->uuid}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('code', 'cancellation_not_allowed');

        $this->assertSame(['authorized', 2], [$join->refresh()->status->value, $this->campaign->refresh()->committed_qty]);
    }

    private function as(string $token, string $method, string $uri): TestResponse
    {
        $this->forgetAuthenticatedUsers();
        $response = $this->withToken($token)->json(strtoupper($method), $uri);
        $this->forgetAuthenticatedUsers();

        return $response;
    }
}
