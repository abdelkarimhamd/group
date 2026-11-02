<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\StatusTransition;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Payments\Models\Refund;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ClosesCampaigns;
use Tests\TestCase;

/**
 * Refund requests (spec, Payments: refunds): never more than was captured
 * minus what is refunded or asked for, and always through a second admin.
 */
class RefundsTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    private Campaign $campaign;

    private CampaignParticipation $join;

    private CustomerOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->campaign = $this->closableCampaign();
        [$this->join] = $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
        $this->order = CustomerOrder::query()->sole();
    }

    #[Test]
    public function a_full_refund_soon_after_capture_waits_for_finance_as_a_void(): void
    {
        $payment = $this->join->latestPayment;

        $refund = $this->requestRefund(['amount_halalas' => 36000, 'reason' => 'customer_cancellation', 'note' => 'Changed their mind'])
            ->assertCreated()
            ->assertJsonPath('data.payment_id', $payment->uuid)
            ->assertJsonPath('data.customer_order_id', $this->order->uuid)
            ->assertJsonPath('data.amount.amount_minor', 36000)
            ->assertJsonPath('data.method', 'void')
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.requested_by.type', 'admin')
            ->assertJsonPath('data.processed_at', null)
            ->json('data');

        $model = Refund::query()->where('uuid', $refund['id'])->sole();
        $this->assertSame("refund:{$model->uuid}", $model->idempotency_key);
        $approval = ApprovalRequest::query()->sole();
        $this->assertSame($approval->uuid, $refund['approval_request_id']);
        $this->assertSame(['manual_refund', 'pending', 'refund', $model->id], [$approval->type->value, $approval->status->value, $approval->subject_type, $approval->subject_id]);
        $this->assertSame([36000, 'void', 'customer_cancellation'], [$approval->payload['amount_halalas'], $approval->payload['method'], $approval->payload['reason']]);
        $this->assertSame('pending_approval', StatusTransition::query()->where('subject_type', 'refund')->where('subject_id', $model->id)->sole()->to_status);
        $this->assertSame(1, AuditLog::query()->where('action', 'refund.request')->count());

        // Nothing is sent to Moyasar until it is approved.
        $this->assertSame(['captured', 0], [$payment->refresh()->status->value, $payment->refunded_amount_halalas]);

        // Everything captured is spoken for now.
        $this->requestRefund(['amount_halalas' => 1, 'reason' => 'other'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'refund_exceeds_captured')
            ->assertJsonValidationErrors('amount_halalas');

        $this->admin('get', '/api/v1/admin/refunds?status=pending_approval', AdminRole::Finance)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $refund['id']);
        $this->admin('get', '/api/v1/admin/orders/'.$this->order->uuid)
            ->assertOk()
            ->assertJsonPath('data.refunds.0.id', $refund['id']);
    }

    #[Test]
    public function partial_and_later_refunds_are_refunds_up_to_what_was_captured(): void
    {
        $this->travel(3)->hours();

        // Order IDs match whatever their case.
        $this->requestRefund(['amount_halalas' => 12000, 'reason' => 'defective', 'customer_order_id' => strtoupper($this->order->uuid)])
            ->assertCreated()
            ->assertJsonPath('data.customer_order_id', $this->order->uuid)
            ->assertJsonPath('data.method', 'refund');
        $this->requestRefund(['amount_halalas' => 24001, 'reason' => 'shortage'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'refund_exceeds_captured');
        $this->requestRefund(['amount_halalas' => 24000, 'reason' => 'shortage'])->assertCreated();

        $this->assertSame(2, Refund::count());
        $this->assertSame(2, ApprovalRequest::count());
    }

    #[Test]
    public function only_captured_payments_of_the_right_order_are_refunded(): void
    {
        [$authorized] = $this->paidJoin($this->closableCampaign(), 1);
        [$elsewhere] = $this->paidJoin($other = $this->closableCampaign(), 3);
        $this->captureAll($other);

        $this->requestRefund(['payment_id' => $authorized->latestPayment->uuid, 'amount_halalas' => 100, 'reason' => 'other'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'payment_not_captured');
        $this->requestRefund(['customer_order_id' => CustomerOrder::query()->where('participation_id', $elsewhere->id)->value('uuid'), 'amount_halalas' => 100, 'reason' => 'other'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'order_not_for_payment');
        $this->requestRefund(['payment_id' => '00000000-0000-4000-8000-000000000000', 'amount_halalas' => 100, 'reason' => 'other'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'payment_not_found');
        $this->requestRefund(['amount_halalas' => 0, 'reason' => 'whim'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_halalas', 'reason']);

        $this->assertSame(0, Refund::count());
    }

    #[Test]
    public function refunds_need_their_permissions(): void
    {
        $this->requestRefund(['amount_halalas' => 100, 'reason' => 'other'], AdminRole::Operations)->assertForbidden();
        $this->admin('get', '/api/v1/admin/refunds', AdminRole::Sourcing)->assertForbidden();

        // Support can ask; finance decides.
        $this->requestRefund(['amount_halalas' => 100, 'reason' => 'other'], AdminRole::Support)->assertCreated();
        $this->admin('get', '/api/v1/admin/refunds', AdminRole::Support)->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function requestRefund(array $input, AdminRole $role = AdminRole::Support): TestResponse
    {
        return $this->admin('post', '/api/v1/admin/refunds', $role, $input + ['payment_id' => $this->join->latestPayment->uuid]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function admin(string $method, string $uri, AdminRole $role = AdminRole::Support, array $body = []): TestResponse
    {
        [, $token] = $this->adminWithToken($role);
        $this->forgetAuthenticatedUsers();
        $response = $this->withToken($token)->json(strtoupper($method), $uri, $body);
        $this->forgetAuthenticatedUsers();

        return $response;
    }
}
