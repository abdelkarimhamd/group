<?php

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\StatusTransition;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Payments\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\FulfilsOrders;
use Tests\TestCase;

/**
 * Customer returns (spec, Orders): recorded by staff within the return
 * window, then approved (a refund through the usual approval) or rejected.
 */
class ReturnsTest extends TestCase
{
    use FulfilsOrders, RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->campaign = $this->closableCampaign();
    }

    #[Test]
    public function an_approved_return_is_refunded_and_the_order_returned(): void
    {
        $sms = $this->fakeSms();
        [$order] = $this->deliveredOrders([3]);
        $phone = $order->user()->sole()->phone_e164;

        $return = $this->record($order, ['reason' => 'defective', 'note' => 'Lamp does not switch on'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.reason', 'defective')
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.recorded_by.type', 'admin')
            ->assertJsonPath('data.refund', null)
            ->assertJsonPath('data.refundable.amount_minor', 36000)
            ->assertJsonPath('data.open', true)
            ->json('data.id');
        $this->assertSame('return_requested', $order->refresh()->status->value);
        $this->record($order, ['reason' => 'other'])->assertStatus(409)->assertJsonPath('code', 'return_exists');
        $this->asAdmin('get', "/api/v1/admin/orders/{$order->uuid}", role: AdminRole::Support)->assertOk()->assertJsonPath('data.return.id', $return);
        $this->asCustomer($order)->assertOk()->assertJsonPath('data.status', 'return_requested');

        // Waiting on a return, the order is not completed when its window ends.
        $this->travel(8)->days();
        $this->artisan('orders:complete-delivered')->assertSuccessful();
        $this->assertSame('return_requested', $order->refresh()->status->value);

        // Accepted less a restocking fee: a refund waits for finance.
        $refundable = 31000;
        $this->decide('approve', $return, ['amount_halalas' => 999999])->assertUnprocessable()->assertJsonPath('code', 'refund_exceeds_captured');
        $this->decide('approve', $return, ['amount_halalas' => $refundable, 'note' => 'Restocking fee kept'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decision_note', 'Restocking fee kept')
            ->assertJsonPath('data.refund.status', 'pending_approval')
            ->assertJsonPath('data.refund.reason', 'customer_return')
            ->assertJsonPath('data.refund.amount.amount_minor', $refundable)
            ->assertJsonPath('data.open', false);
        $this->assertSame(sprintf('قبلنا إرجاع الطلب %s. سنعيد %s ريال إلى بطاقتك بعد اعتماد الاسترداد.', $order->order_number, number_format($refundable / 100, 2, '.', '')), last($sms->to($phone)));
        $this->decide('reject', $return, ['note' => 'Changed our mind'])->assertStatus(409);

        // Finance approves; the refund goes through and the return is done.
        $refund = Refund::query()->sole();
        $this->asAdmin('post', "/api/v1/admin/approval-requests/{$refund->approvalRequest->uuid}/approve", role: AdminRole::Finance)->assertOk();
        $this->assertSame('succeeded', $refund->refresh()->status->value);
        $this->assertSame(['returned', 'partially_refunded'], [$order->refresh()->status->value, $order->payment->status->value]);
        $this->assertSame('completed', OrderReturn::query()->sole()->status->value);
        $this->assertSame('completed', $this->campaign->refresh()->status->value);
        $this->assertSame(
            [['delivered', 'return_requested', 'return_requested'], ['return_requested', 'returned', 'refunded']],
            array_slice($this->history('customer_order', $order->id), -2),
        );
        $this->assertSame(
            [[null, 'requested', null], ['requested', 'approved', null], ['approved', 'completed', 'refunded']],
            $this->history('order_return', OrderReturn::query()->sole()->id),
        );
        $this->assertSame(
            ['order_return.record', 'order_return.approve'],
            AuditLog::query()->where('action', 'like', 'order_return.%')->orderBy('id')->pluck('action')->all(),
        );
        $this->asAdmin('get', '/api/v1/admin/returns?status=completed', role: AdminRole::Support)->assertOk()->assertJsonCount(1, 'data');
        $this->asAdmin('get', '/api/v1/admin/returns?status=requested', role: AdminRole::Support)->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function a_rejected_return_puts_the_order_back_to_delivered(): void
    {
        $sms = $this->fakeSms();
        [$order] = $this->deliveredOrders([3]);
        $return = $this->record($order, ['reason' => 'changed_mind'])->assertCreated()->json('data.id');

        $this->decide('reject', $return, ['note' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->decide('reject', $return, ['note' => 'Opened and used'], AdminRole::Operations)->assertForbidden();
        $this->decide('reject', $return, ['note' => 'Opened and used'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.decided_by.type', 'admin')
            ->assertJsonPath('data.open', false);
        $this->assertSame('delivered', $order->refresh()->status->value);
        $this->assertSame("لم نتمكن من قبول إرجاع الطلب {$order->order_number}. تواصل معنا إن كان لديك أي سؤال.", last($sms->to($order->user()->sole()->phone_e164)));
        $this->decide('approve', $return, ['amount_halalas' => 100])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->record($order, ['reason' => 'changed_mind'])->assertStatus(409)->assertJsonPath('code', 'return_exists');

        // The window ends as usual.
        $this->travel(8)->days();
        $this->artisan('orders:complete-delivered')->assertSuccessful();
        $this->assertSame(['completed', 'completed'], [$order->refresh()->status->value, $this->campaign->refresh()->status->value]);
    }

    #[Test]
    public function a_return_whose_refund_finance_rejected_is_decided_again(): void
    {
        [$order] = $this->deliveredOrders([3]);
        $return = $this->record($order, ['reason' => 'not_as_described'])->json('data.id');
        $this->decide('approve', $return, ['amount_halalas' => 36000])->assertOk();
        $first = Refund::query()->sole();

        $this->asAdmin('post', "/api/v1/admin/approval-requests/{$first->approvalRequest->uuid}/reject", ['reason' => 'Ask for photos first'], AdminRole::Finance)->assertOk();
        $this->asAdmin('get', "/api/v1/admin/orders/{$order->uuid}", role: AdminRole::Support)
            ->assertOk()
            ->assertJsonPath('data.status', 'return_requested')
            ->assertJsonPath('data.return.refund.status', 'rejected')
            ->assertJsonPath('data.return.open', true);

        // Approved again for less; a full refund also closes it.
        $this->decide('approve', $return, ['amount_halalas' => 20000])->assertOk()->assertJsonPath('data.refund.amount.amount_minor', 20000);
        $second = Refund::query()->latest('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $this->asAdmin('post', "/api/v1/admin/approval-requests/{$second->approvalRequest->uuid}/approve", role: AdminRole::Finance)->assertOk();
        $this->assertSame(['returned', 'completed'], [$order->refresh()->status->value, OrderReturn::query()->sole()->status->value]);
    }

    #[Test]
    public function a_full_refund_on_a_waiting_return_closes_it_too(): void
    {
        [$order] = $this->deliveredOrders([3]);
        $this->record($order, ['reason' => 'wrong_item'])->assertCreated();

        // Finance refunds everything directly, without deciding the return.
        $this->asAdmin('post', '/api/v1/admin/refunds', ['payment_id' => $order->payment->uuid, 'amount_halalas' => 36000, 'reason' => 'other'], AdminRole::Support)->assertCreated();
        $this->asAdmin('post', '/api/v1/admin/approval-requests/'.Refund::query()->sole()->approvalRequest->uuid.'/approve', role: AdminRole::Finance)->assertOk();

        $return = OrderReturn::query()->sole();
        $this->assertSame(['returned', 'completed'], [$order->refresh()->status->value, $return->status->value]);
        $this->assertNotNull($return->decided_at);
        $this->assertNull($return->decided_by_admin_id);
    }

    #[Test]
    public function returns_are_recorded_only_for_delivered_orders_within_their_window(): void
    {
        [$shipped, $late] = $this->readyOrders($this->campaign, [2, 1]);
        $this->ship($shipped);
        $this->record($shipped, ['reason' => 'defective'])->assertStatus(409)->assertJsonPath('code', 'invalid_transition');

        $this->deliver($this->ship($late));
        $this->record($late, ['reason' => 'broken'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->record($late, ['reason' => 'defective'], AdminRole::Operations)->assertForbidden();
        $this->asAdmin('post', '/api/v1/admin/orders/00000000-0000-4000-8000-000000000000/return', ['reason' => 'defective'], AdminRole::Support)->assertNotFound();
        $this->decide('approve', '00000000-0000-4000-8000-000000000000', ['amount_halalas' => 1])->assertNotFound();

        $this->travelTo($late->refresh()->return_window_ends_at);
        $this->record($late, ['reason' => 'defective'])->assertStatus(409)->assertJsonPath('code', 'return_window_ended');
        $this->asAdmin('get', '/api/v1/admin/returns', role: AdminRole::Sourcing)->assertOk()->assertJsonCount(0, 'data');
    }

    /**
     * Ready orders, handed to the courier and delivered now.
     *
     * @param  list<int>  $quantities
     * @return list<CustomerOrder>
     */
    private function deliveredOrders(array $quantities): array
    {
        $orders = $this->readyOrders($this->campaign, $quantities);

        foreach ($orders as $order) {
            $this->deliver($this->ship($order));
        }

        return array_map(fn (CustomerOrder $order) => $order->refresh(), $orders);
    }

    private function ship(CustomerOrder $order): string
    {
        return $this->asAdmin('post', "/api/v1/admin/orders/{$order->uuid}/shipment", ['courier' => 'SMSA', 'tracking_number' => "SMSA-{$order->id}"])->assertCreated()->json('data.id');
    }

    private function deliver(string $shipment): void
    {
        $this->asAdmin('post', "/api/v1/admin/shipments/{$shipment}/events", ['status' => 'delivered', 'occurred_at' => now()->toIso8601ZuluString()])->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function record(CustomerOrder $order, array $body, AdminRole $role = AdminRole::Support): TestResponse
    {
        return $this->asAdmin('post', "/api/v1/admin/orders/{$order->uuid}/return", $body, $role);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function decide(string $decision, string $return, array $body, AdminRole $role = AdminRole::Support): TestResponse
    {
        return $this->asAdmin('post', "/api/v1/admin/returns/{$return}/{$decision}", $body, $role);
    }

    /**
     * @return list<array{string|null, string, string|null}>
     */
    private function history(string $type, int $id): array
    {
        return StatusTransition::query()
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->orderBy('id')
            ->get()
            ->map(fn (StatusTransition $row) => [$row->from_status, $row->to_status, $row->reason])
            ->all();
    }
}
