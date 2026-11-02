<?php

namespace Tests\Feature\Orders;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\User;
use App\Modules\Orders\Models\CustomerOrder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ClosesCampaigns;
use Tests\TestCase;

class AdminOrdersTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    #[Test]
    public function operations_find_orders_by_status_campaign_number_or_phone(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $campaign = $this->closableCampaign();
        $other = $this->closableCampaign();
        [$first] = $this->paidJoin($campaign, 2);
        [$second] = $this->paidJoin($campaign, 1);
        $this->paidJoin($other, 3);
        $this->captureAll($campaign);
        $this->captureAll($other);

        $orderOf = fn ($participation) => CustomerOrder::query()->where('participation_id', $participation->id)->sole();
        $order = $orderOf($first);
        $phone = User::query()->whereKey($first->user_id)->value('phone_e164');

        $this->admin('/api/v1/admin/orders')->assertOk()->assertJsonCount(3, 'data');
        $this->admin("/api/v1/admin/orders?campaign_id={$campaign->uuid}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $orderOf($second)->uuid)
            ->assertJsonPath('data.1.id', $order->uuid);
        $this->admin('/api/v1/admin/orders?status=shipped')->assertOk()->assertJsonCount(0, 'data');
        $this->admin('/api/v1/admin/orders?q='.strtolower($order->order_number))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $order->uuid);
        // The customer's number as they would say it: 05XXXXXXXX.
        $this->admin('/api/v1/admin/orders?q=0'.substr($phone, 4))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer.phone', $phone);
        $this->admin('/api/v1/admin/orders?per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.per_page', 1);
        $this->admin('/api/v1/admin/orders?status=lost')->assertUnprocessable();

        // Three units in all stay on the 120.00 tier.
        $this->admin("/api/v1/admin/orders/{$order->uuid}", AdminRole::Support)
            ->assertOk()
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.customer.id', User::query()->whereKey($first->user_id)->value('uuid'))
            ->assertJsonPath('data.participation_id', $first->uuid)
            ->assertJsonPath('data.payment_id', $first->latestPayment->uuid)
            ->assertJsonPath('data.total.amount_minor', 24000)
            ->assertJsonPath('data.refunds', []);
    }

    private function admin(string $uri, AdminRole $role = AdminRole::Operations): TestResponse
    {
        [, $token] = $this->adminWithToken($role);
        $this->forgetAuthenticatedUsers();
        $response = $this->withToken($token)->getJson($uri);
        $this->forgetAuthenticatedUsers();

        return $response;
    }
}
