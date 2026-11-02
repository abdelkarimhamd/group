<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\StatusTransition;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Finance\Enums\ApprovalType;
use App\Modules\Finance\Enums\LedgerAccountCode;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Finance\Models\LedgerTransaction;
use App\Modules\Finance\Services\Approvals;
use App\Modules\Finance\Services\Ledger;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\Invoice;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Jobs\ExecuteRefund;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\Refunds;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\ClosesCampaigns;
use Tests\Fakes\ScriptedMoyasarGateway;
use Tests\TestCase;

/**
 * A refund is sent to Moyasar only once a second admin with the finance
 * role approves it (spec, Payments: refunds), then recorded with its credit
 * note and ledger posting.
 */
class ApprovingRefundsTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    private Campaign $campaign;

    private CampaignParticipation $join;

    private PaymentAuthorization $payment;

    private CustomerOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->campaign = $this->closableCampaign();
        [$this->join] = $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
        $this->payment = $this->join->latestPayment()->firstOrFail();
        $this->order = CustomerOrder::query()->sole();
    }

    #[Test]
    public function a_full_refund_approved_soon_after_capture_is_a_void_and_settles_everything(): void
    {
        $this->assertSame([36000, -31304, -4696], $this->balances());

        [$refund, $approval] = $this->requestRefund(36000);
        $this->assertSame('void', $refund->method->value);

        $this->decide('get', '/api/v1/admin/approval-requests')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $approval->uuid)
            ->assertJsonPath('data.0.type', 'manual_refund')
            ->assertJsonPath('data.0.subject_type', 'refund')
            ->assertJsonPath('data.0.subject_id', $refund->uuid)
            ->assertJsonPath('data.0.payload.amount_halalas', 36000);

        $this->decide('post', "/api/v1/admin/approval-requests/{$approval->uuid}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.decided_by.type', 'admin')
            ->assertJsonPath('data.reason', null);

        // The approval sent it after commit (the test queue is sync).
        $refund->refresh();
        $this->assertSame(['succeeded', 'void'], [$refund->status->value, $refund->method->value]);
        $this->assertSame(['pending_approval', 'approved', 'processing', 'succeeded'], $this->transitions('refund', $refund->id));
        $this->payment->refresh();
        $this->assertSame(['refunded', 36000], [$this->payment->status->value, $this->payment->refunded_amount_halalas]);
        $void = PaymentTransaction::query()->where('idempotency_key', "refund:{$refund->uuid}")->sole();
        $this->assertSame(['void', 'succeeded', 1], [$void->type->value, $void->status->value, $void->attempts]);

        // Nothing was sent to the supplier yet, so the order is cancelled.
        $this->assertSame('cancelled', $this->order->refresh()->status->value);

        // A credit note for the whole invoice, and the ledger back to zero.
        $creditNote = Invoice::query()->whereKey($refund->credit_note_id)->sole();
        $this->assertSame(['credit_note', 36000, 4696], [$creditNote->type->value, $creditNote->total_halalas, $creditNote->vat_halalas]);
        $this->assertSame([0, 0, 0], $this->balances());
        $this->assertSame(['capture', 'refund'], LedgerTransaction::query()->orderBy('id')->pluck('type')->map->value->all());

        // The customer hears, and finance can see who did what.
        $this->assertSame(1, Notification::query()->where('template_key', 'refunded')->where('channel', 'sms')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'approval.approve')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'refund.succeed')->count());

        // A decided request stays decided.
        $this->decide('post', "/api/v1/admin/approval-requests/{$approval->uuid}/approve")
            ->assertConflict()
            ->assertJsonPath('code', 'invalid_transition');
        $this->decide('get', '/api/v1/admin/approval-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->decide('get', '/api/v1/admin/approval-requests?status=approved')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_partial_refund_after_the_void_window_is_a_refund_with_its_own_credit_note(): void
    {
        $this->travel(3)->hours();
        [$refund, $approval] = $this->requestRefund(12000, 'defective');
        $this->assertSame('refund', $refund->method->value);

        $this->decide('post', "/api/v1/admin/approval-requests/{$approval->uuid}/approve")->assertOk();

        $this->assertSame('succeeded', $refund->refresh()->status->value);
        $this->payment->refresh();
        $this->assertSame(['partially_refunded', 12000], [$this->payment->status->value, $this->payment->refunded_amount_halalas]);
        $this->assertNotSame('cancelled', $this->order->refresh()->status->value);

        $creditNote = Invoice::query()->whereKey($refund->credit_note_id)->sole();
        $this->assertSame([12000, 1565], [$creditNote->total_halalas, $creditNote->vat_halalas]);
        $this->assertCount(1, $creditNote->lines);
        $this->assertSame([24000, -20869, -3131], $this->balances());

        // The rest can still be refunded.
        [$rest, $restApproval] = $this->requestRefund(24000);
        $this->decide('post', "/api/v1/admin/approval-requests/{$restApproval->uuid}/approve")->assertOk();
        $this->assertSame('succeeded', $rest->refresh()->status->value);
        $this->assertSame(['refunded', 36000], [$this->payment->refresh()->status->value, $this->payment->refunded_amount_halalas]);
        $this->assertSame('cancelled', $this->order->refresh()->status->value);
        $this->assertSame([0, 0, 0], $this->balances());
    }

    #[Test]
    public function a_void_moyasar_refuses_is_sent_again_as_a_refund(): void
    {
        $gateway = $this->scripted();
        $gateway->refundOutcomes[$this->payment->provider_payment_id] = ['declined'];
        [$refund, $approval] = $this->requestRefund(36000);

        $this->decide('post', "/api/v1/admin/approval-requests/{$approval->uuid}/approve")->assertOk();

        $refund->refresh();
        $this->assertSame(['succeeded', 'refund'], [$refund->status->value, $refund->method->value]);
        $this->assertSame([$this->payment->provider_payment_id], $gateway->voids);
        $this->assertSame([[$this->payment->provider_payment_id, 36000, "refund:{$refund->uuid}:refund"]], $gateway->refunds);
        $this->assertSame(
            [['void', 'failed'], ['refund', 'succeeded']],
            PaymentTransaction::query()->where('idempotency_key', 'like', "refund:{$refund->uuid}%")->orderBy('id')->get()
                ->map(fn (PaymentTransaction $t) => [$t->type->value, $t->status->value])->all(),
        );
        $this->assertSame(['refunded', 36000], [$this->payment->refresh()->status->value, $this->payment->refunded_amount_halalas]);
    }

    #[Test]
    public function a_refund_whose_answer_was_lost_is_recorded_once_from_moyasar(): void
    {
        $this->travel(3)->hours();
        $gateway = $this->scripted();
        $gateway->refundOutcomes[$this->payment->provider_payment_id] = ['timeout_after_refund'];
        [$refund, $approval] = $this->requestRefund(10000);

        $this->decide('post', "/api/v1/admin/approval-requests/{$approval->uuid}/approve")->assertOk();

        $this->assertCount(1, $gateway->refunds);
        $this->assertSame('succeeded', $refund->refresh()->status->value);
        $attempt = PaymentTransaction::query()->where('idempotency_key', "refund:{$refund->uuid}")->sole();
        $this->assertSame(['succeeded', 'fetch'], [$attempt->status->value, $attempt->response_payload['resolved_by']]);
        $this->assertSame(10000, $this->payment->refresh()->refunded_amount_halalas);
    }

    #[Test]
    public function an_unknown_refund_outcome_is_checked_with_moyasar_before_sending_it_again(): void
    {
        Queue::fake([ExecuteRefund::class]);
        $this->travel(3)->hours();
        $gateway = $this->scripted();
        $gateway->refundOutcomes[$this->payment->provider_payment_id] = ['timeout'];
        [$refund, $approval] = $this->requestRefund(10000);
        $this->decide('post', "/api/v1/admin/approval-requests/{$approval->uuid}/approve")->assertOk();
        Queue::assertPushed(ExecuteRefund::class, fn (ExecuteRefund $job) => $job->refundId === $refund->id);

        try {
            app(Refunds::class)->execute($refund);
            $this->fail('An unknown outcome is retried.');
        } catch (GatewayException) {
        }

        $this->assertSame('processing', $refund->refresh()->status->value);
        $attempt = PaymentTransaction::query()->where('idempotency_key', "refund:{$refund->uuid}")->sole();
        $this->assertSame(['pending', 1], [$attempt->status->value, $attempt->attempts]);

        // Another refund of the same payment waits for this one.
        $this->travel(1)->minutes();
        [$other, $otherApproval] = $this->requestRefund(5000);
        $this->decide('post', "/api/v1/admin/approval-requests/{$otherApproval->uuid}/approve")->assertOk();

        try {
            app(Refunds::class)->execute($other);
            $this->fail('A second refund of a payment waits for the first.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('in flight', $e->getMessage());
        }

        // The retry asks first (nothing went back), then sends the same refund again.
        $fetches = count($gateway->fetches);
        app(Refunds::class)->execute($refund);

        $this->assertGreaterThan($fetches, count($gateway->fetches));
        $this->assertSame([10000, 10000], array_column($gateway->refunds, 1));
        $this->assertSame(["refund:{$refund->uuid}", "refund:{$refund->uuid}"], array_column($gateway->refunds, 2));
        $this->assertSame(['succeeded', 2], [$attempt->refresh()->status->value, $attempt->attempts]);
        $this->assertSame('succeeded', $refund->refresh()->status->value);

        // Run again, nothing more is sent.
        app(Refunds::class)->execute($refund);
        $this->assertCount(2, $gateway->refunds);

        app(Refunds::class)->execute($other);
        $this->assertSame(15000, $this->payment->refresh()->refunded_amount_halalas);
    }

    #[Test]
    public function a_refund_moyasar_refuses_fails_and_moves_no_money(): void
    {
        $this->travel(3)->hours();
        $gateway = $this->scripted();
        $gateway->refundOutcomes[$this->payment->provider_payment_id] = ['declined'];
        [$refund, $approval] = $this->requestRefund(10000);

        $this->decide('post', "/api/v1/admin/approval-requests/{$approval->uuid}/approve")->assertOk();

        $refund->refresh();
        $this->assertSame('failed', $refund->status->value);
        $this->assertNotNull($refund->processed_at);
        $this->assertNull($refund->credit_note_id);
        $this->assertSame(['captured', 0], [$this->payment->refresh()->status->value, $this->payment->refunded_amount_halalas]);
        $this->assertSame(1, AuditLog::query()->where('action', 'refund.fail')->count());
        $this->assertSame([36000, -31304, -4696], $this->balances());
        $this->assertSame(0, Notification::query()->where('template_key', 'refunded')->count());

        // The amount is free to ask for again.
        $this->requestRefund(36000);
    }

    #[Test]
    public function a_rejected_refund_sends_nothing_and_frees_the_amount(): void
    {
        $gateway = $this->scripted();
        [$refund, $approval] = $this->requestRefund(36000);

        $this->decide('post', "/api/v1/admin/approval-requests/{$approval->uuid}/reject", ['reason' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->decide('post', "/api/v1/admin/approval-requests/{$approval->uuid}/reject", ['reason' => 'Customer already received a replacement'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.reason', 'Customer already received a replacement');

        $this->assertSame('rejected', $refund->refresh()->status->value);
        $this->assertSame([[], []], [$gateway->voids, $gateway->refunds]);
        $this->assertSame(1, AuditLog::query()->where('action', 'approval.reject')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'refund.reject')->count());
        $this->requestRefund(36000);
    }

    #[Test]
    public function the_requester_never_decides_their_own_request(): void
    {
        [$requester, $token] = $this->adminWithToken(AdminRole::Finance);
        $this->forgetAuthenticatedUsers();
        $approval = ApprovalRequest::query()->where('uuid', $this->withToken($token)->postJson('/api/v1/admin/refunds', [
            'payment_id' => $this->payment->uuid, 'amount_halalas' => 1000, 'reason' => 'other',
        ])->assertCreated()->json('data.approval_request_id'))->sole();
        $this->forgetAuthenticatedUsers();

        $this->withToken($token)->postJson("/api/v1/admin/approval-requests/{$approval->uuid}/approve")
            ->assertForbidden()
            ->assertJsonPath('code', 'same_admin');
        $this->forgetAuthenticatedUsers();
        $this->withToken($token)->postJson("/api/v1/admin/approval-requests/{$approval->uuid}/reject", ['reason' => 'Changed my mind'])
            ->assertForbidden()
            ->assertJsonPath('code', 'same_admin');
        $this->assertSame('pending', $approval->refresh()->status->value);
        $this->assertSame($requester->id, $approval->requested_by_admin_id);
    }

    #[Test]
    public function only_finance_decides_and_only_what_can_be_carried_out(): void
    {
        [, $approval] = $this->requestRefund(1000);

        $this->decide('post', "/api/v1/admin/approval-requests/{$approval->uuid}/approve", role: AdminRole::SuperAdmin)->assertForbidden();
        $this->decide('post', "/api/v1/admin/approval-requests/{$approval->uuid}/approve", role: AdminRole::Support)->assertForbidden();
        $this->decide('get', '/api/v1/admin/approval-requests', role: AdminRole::Sourcing)->assertForbidden();
        $this->decide('get', '/api/v1/admin/approval-requests?status=open')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->decide('post', '/api/v1/admin/approval-requests/00000000-0000-4000-8000-000000000000/approve')->assertNotFound();

        // Types nothing carries out yet cannot be approved.
        [$admin] = $this->adminWithToken(AdminRole::Finance);
        $adjustment = DB::transaction(fn () => app(Approvals::class)->request(ApprovalType::LedgerAdjustment, $this->order, ['amount_halalas' => 100], $admin));
        $this->decide('post', "/api/v1/admin/approval-requests/{$adjustment->uuid}/approve")
            ->assertConflict()
            ->assertJsonPath('code', 'invalid_transition');
        $this->assertSame('pending', $adjustment->refresh()->status->value);
    }

    /**
     * @return array{Refund, ApprovalRequest}
     */
    private function requestRefund(int $halalas, string $reason = 'customer_cancellation'): array
    {
        $id = $this->decide('post', '/api/v1/admin/refunds', [
            'payment_id' => $this->payment->uuid,
            'amount_halalas' => $halalas,
            'reason' => $reason,
        ], AdminRole::Support)->assertCreated()->json('data.id');
        $refund = Refund::query()->where('uuid', $id)->sole();

        return [$refund, ApprovalRequest::query()->whereKey($refund->approval_request_id)->sole()];
    }

    /**
     * A request by a new admin (finance unless said otherwise), so never the requester.
     *
     * @param  array<string, mixed>  $body
     */
    private function decide(string $method, string $uri, array $body = [], AdminRole $role = AdminRole::Finance): TestResponse
    {
        [, $token] = $this->adminWithToken($role);
        $this->forgetAuthenticatedUsers();
        $response = $this->withToken($token)->json(strtoupper($method), $uri, $body);
        $this->forgetAuthenticatedUsers();

        return $response;
    }

    private function scripted(): ScriptedMoyasarGateway
    {
        $gateway = new ScriptedMoyasarGateway;
        $gateway->report((string) $this->payment->provider_payment_id, $this->payment, status: 'captured');
        $gateway->setStatus((string) $this->payment->provider_payment_id, 'captured', $this->payment->captured_amount_halalas);
        $this->app->instance(PaymentGateway::class, $gateway);

        return $gateway;
    }

    /**
     * Moyasar clearing, customer prepayments and output VAT (debits minus credits).
     *
     * @return list<int>
     */
    private function balances(): array
    {
        $ledger = app(Ledger::class);

        return [
            $ledger->balance(LedgerAccountCode::MoyasarClearing)->minor,
            $ledger->balance(LedgerAccountCode::CustomerPrepayments)->minor,
            $ledger->balance(LedgerAccountCode::VatOutputPayable)->minor,
        ];
    }

    /**
     * @return list<string>
     */
    private function transitions(string $type, int $id): array
    {
        return StatusTransition::query()->where('subject_type', $type)->where('subject_id', $id)->orderBy('id')->pluck('to_status')->all();
    }
}
