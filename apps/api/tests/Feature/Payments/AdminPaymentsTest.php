<?php

namespace Tests\Feature\Payments;

use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Payments\Gateway\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithCampaigns;
use Tests\Fakes\ScriptedMoyasarGateway;
use Tests\TestCase;

/**
 * Holds and charges in the admin portal (payments.view).
 */
class AdminPaymentsTest extends TestCase
{
    use InteractsWithCampaigns, RefreshDatabase;

    private Campaign $campaign;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->campaign = Campaign::factory()->active()->create();
        [, $this->token] = $this->adminWithToken(AdminRole::Finance);
    }

    #[Test]
    public function payments_are_listed_newest_first(): void
    {
        $first = $this->joined();
        $second = $this->joined();
        $this->authorizeHold($second);

        $this->admin('/api/v1/admin/payments')
            ->assertOk()
            ->assertJsonPath('data.0.id', $second->latestPayment->uuid)
            ->assertJsonPath('data.0.status', 'authorized')
            ->assertJsonPath('data.0.participation_id', $second->uuid)
            ->assertJsonPath('data.0.campaign.code', $this->campaign->code)
            ->assertJsonPath('data.0.authorized_amount.amount_minor', 12000)
            ->assertJsonPath('data.0.transactions', [])
            ->assertJsonPath('data.1.id', $first->latestPayment->uuid)
            ->assertJsonPath('data.1.status', 'pending_authorization')
            ->assertJsonPath('meta.next_cursor', null);
    }

    #[Test]
    public function payments_can_be_filtered_by_status_and_campaign(): void
    {
        $this->joined();
        $authorized = $this->joined();
        $this->authorizeHold($authorized);
        $other = Campaign::factory()->active()->create();
        $elsewhere = $this->joined($other);

        $this->assertSame([$authorized->latestPayment->uuid], $this->ids('/api/v1/admin/payments?status=authorized'));
        $this->assertSame([$elsewhere->latestPayment->uuid], $this->ids("/api/v1/admin/payments?campaign_id={$other->uuid}"));
        $this->assertSame([], $this->ids("/api/v1/admin/payments?campaign_id={$other->uuid}&status=authorized"));
    }

    #[Test]
    public function holds_running_out_soonest_come_first(): void
    {
        $holds = [];

        foreach ([10, 2, 5, 1] as $days) {
            $participation = $this->joined();
            $this->authorizeHold($participation);
            $payment = $participation->latestPayment;
            $payment->forceFill(['expires_at' => CarbonImmutable::now()->addDays($days)])->save();
            $holds[$days] = $payment->uuid;
        }

        // A hold still waiting for the customer is not running out.
        $this->joined()->latestPayment->forceFill(['expires_at' => CarbonImmutable::now()->addDay()])->save();

        $this->assertSame([$holds[1], $holds[2], $holds[5]], $this->ids('/api/v1/admin/payments?expiring_within_days=5'));

        // Pages follow the same order.
        $page = $this->admin('/api/v1/admin/payments?expiring_within_days=5&per_page=2')->assertOk();
        $this->assertSame([$holds[1], $holds[2]], array_column($page->json('data'), 'id'));
        $next = $this->admin('/api/v1/admin/payments?expiring_within_days=5&per_page=2&cursor='.$page->json('meta.next_cursor'))->assertOk();
        $this->assertSame([$holds[5]], array_column($next->json('data'), 'id'));
    }

    #[Test]
    public function filters_are_validated(): void
    {
        $this->admin('/api/v1/admin/payments?expiring_within_days=15')->assertUnprocessable()->assertJsonValidationErrors('expiring_within_days');
        $this->admin('/api/v1/admin/payments?expiring_within_days=0')->assertUnprocessable()->assertJsonValidationErrors('expiring_within_days');
        $this->admin('/api/v1/admin/payments?status=charged')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->admin('/api/v1/admin/payments?campaign_id=GB-2611-0001')->assertUnprocessable()->assertJsonValidationErrors('campaign_id');
    }

    #[Test]
    public function filters_sent_in_a_json_body_are_read_like_the_query(): void
    {
        $participation = $this->joined();
        $this->authorizeHold($participation);

        $this->withToken($this->token)->json('GET', '/api/v1/admin/payments', ['expiring_within_days' => 14])
            ->assertOk()
            ->assertJsonPath('data.0.id', $participation->latestPayment->uuid);
    }

    #[Test]
    public function a_cursor_with_an_impossible_time_zone_is_refused(): void
    {
        $cursor = fn (string $at): string => rtrim(strtr(base64_encode(json_encode(['expires_at' => $at, 'id' => 1, '_pointsToNextItems' => true], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

        $this->admin('/api/v1/admin/payments?expiring_within_days=5&cursor='.$cursor('2026-11-10T09:00:00+03:00'))->assertOk();
        $this->admin('/api/v1/admin/payments?expiring_within_days=5&cursor='.$cursor('2026-11-10T09:00:00+99:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
    }

    #[Test]
    public function one_payment_shows_every_call_made_to_the_provider(): void
    {
        $gateway = new ScriptedMoyasarGateway;
        $this->app->instance(PaymentGateway::class, $gateway);
        [, $customer, $address] = $this->customerReadyToJoin();
        $payment = $this->joinAs($customer, $this->campaign, $address)->latestPayment;
        $gateway->report('pay_1', $payment, amount: 9999);
        $this->withToken($customer)->postJson("/api/v1/payments/{$payment->uuid}/sync", ['provider_payment_id' => 'pay_1'])->assertOk();
        $this->forgetAuthenticatedUsers();

        $this->admin("/api/v1/admin/payments/{$payment->uuid}")
            ->assertOk()
            ->assertJsonPath('data.status', 'voided')
            ->assertJsonPath('data.rejection_reason', 'amount_mismatch')
            ->assertJsonPath('data.provider_payment_id', 'pay_1')
            ->assertJsonPath('data.provider_status', 'voided')
            ->assertJsonPath('data.transactions.0.type', 'void')
            ->assertJsonPath('data.transactions.0.idempotency_key', 'void:'.$payment->uuid)
            ->assertJsonPath('data.transactions.0.status', 'succeeded')
            ->assertJsonPath('data.transactions.0.requested_by.type', 'user');
    }

    #[Test]
    public function payments_need_payments_view(): void
    {
        $payment = $this->joined()->latestPayment;
        [, $operations] = $this->adminWithToken(AdminRole::Operations);

        $this->withToken($operations)->getJson('/api/v1/admin/payments')->assertForbidden();
        $this->forgetAuthenticatedUsers();
        $this->withToken($operations)->getJson("/api/v1/admin/payments/{$payment->uuid}")->assertForbidden();
        $this->forgetAuthenticatedUsers();

        [, $support] = $this->adminWithToken(AdminRole::Support);
        $this->withToken($support)->getJson("/api/v1/admin/payments/{$payment->uuid}")->assertOk();
    }

    #[Test]
    public function an_unknown_payment_is_not_found(): void
    {
        $this->admin('/api/v1/admin/payments/'.Str::uuid())->assertNotFound();
    }

    private function joined(?Campaign $campaign = null): CampaignParticipation
    {
        [, $token, $address] = $this->customerReadyToJoin();

        return $this->joinAs($token, $campaign ?? $this->campaign, $address);
    }

    private function admin(string $uri): TestResponse
    {
        $response = $this->withToken($this->token)->getJson($uri);
        $this->forgetAuthenticatedUsers();

        return $response;
    }

    /**
     * @return list<string>
     */
    private function ids(string $uri): array
    {
        return array_column($this->admin($uri)->assertOk()->json('data'), 'id');
    }
}
