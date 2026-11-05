<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\StatusTransition;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentGateway;
use App\Modules\Payments\Jobs\ExecuteManualVoid;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\PaymentTransaction;
use App\Modules\Payments\Services\ManualVoids;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ClosesCampaigns;
use Tests\Fakes\ScriptedMoyasarGateway;
use Tests\TestCase;

/**
 * Releasing a hold Moyasar still keeps although it no longer counts here
 * (reconciliation's orphan_hold and stuck_void), through a second admin.
 */
class ManualVoidsTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase;

    private Campaign $campaign;

    private PaymentAuthorization $payment;

    private ScriptedMoyasarGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->campaign = $this->closableCampaign();
        [$join] = $this->paidJoin($this->campaign, 1);
        $this->payment = $join->latestPayment()->firstOrFail();
        // We failed it; Moyasar authorized it anyway.
        $this->payment->forceFill(['status' => 'failed'])->save();
        $this->gateway = new ScriptedMoyasarGateway;
        $this->gateway->report((string) $this->payment->provider_payment_id, $this->payment);
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    #[Test]
    public function a_hold_moyasar_still_keeps_is_released_once_finance_approves(): void
    {
        $finance = $this->token(AdminRole::Finance);
        $this->as($finance, 'get', "/api/v1/admin/payments/{$this->payment->uuid}")->assertOk()->assertJsonPath('data.void_requestable', true);
        $this->as($finance, 'get', '/api/v1/admin/payments')->assertOk()->assertJsonPath('data.0.void_requestable', null);

        $approval = $this->as($finance, 'post', "/api/v1/admin/payments/{$this->payment->uuid}/void", ['reason' => 'Orphan hold from reconciliation'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'manual_void')
            ->assertJsonPath('data.subject_id', $this->payment->uuid)
            ->assertJsonPath('data.payload.reason', 'Orphan hold from reconciliation')
            ->assertJsonPath('data.payload.status', 'failed')
            ->json('data');
        // Asking again finds the same request.
        $this->as($this->token(AdminRole::Finance), 'post', "/api/v1/admin/payments/{$this->payment->uuid}/void", ['reason' => 'Again'])
            ->assertCreated()
            ->assertJsonPath('data.id', $approval['id']);
        $this->assertSame([], $this->gateway->voids);

        $this->as($finance, 'post', "/api/v1/admin/approval-requests/{$approval['id']}/approve")->assertForbidden();
        $this->as($this->token(AdminRole::Finance), 'post', "/api/v1/admin/approval-requests/{$approval['id']}/approve")->assertOk();

        // Asked Moyasar first, then voided once with its own key.
        $this->assertSame([(string) $this->payment->provider_payment_id], $this->gateway->voids);
        $this->assertSame(['voided', 'voided'], [$this->payment->refresh()->status->value, $this->payment->provider_status]);
        $attempt = PaymentTransaction::query()->where('idempotency_key', "void:{$this->payment->uuid}:{$approval['id']}")->sole();
        $this->assertSame(['void', 'succeeded', 1], [$attempt->type->value, $attempt->status->value, $attempt->attempts]);
        $this->assertNotNull(ApprovalRequest::query()->where('uuid', $approval['id'])->value('executed_at'));
        $last = StatusTransition::query()->where('subject_type', 'payment_authorization')->where('subject_id', $this->payment->id)->latest('id')->firstOrFail();
        $this->assertSame(['failed', 'voided', 'manual_void'], [$last->from_status, $last->to_status, $last->reason]);
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.manual_void')->count());

        // Running the job again sends nothing.
        (new ExecuteManualVoid(ApprovalRequest::query()->where('uuid', $approval['id'])->value('id')))->handle(app(ManualVoids::class));
        $this->assertCount(1, $this->gateway->voids);
    }

    #[Test]
    public function an_unknown_outcome_is_checked_with_moyasar_before_voiding_again(): void
    {
        Queue::fake([ExecuteManualVoid::class]);
        $id = $this->as($this->token(AdminRole::Finance), 'post', "/api/v1/admin/payments/{$this->payment->uuid}/void", ['reason' => 'Orphan hold'])->json('data.id');
        $this->as($this->token(AdminRole::Finance), 'post', "/api/v1/admin/approval-requests/{$id}/approve")->assertOk();
        $request = ApprovalRequest::query()->where('uuid', $id)->sole();
        Queue::assertPushed(ExecuteManualVoid::class, fn (ExecuteManualVoid $job) => $job->approvalRequestId === $request->id);

        $this->gateway->down = true;

        try {
            app(ManualVoids::class)->execute($request);
            $this->fail('An unreachable Moyasar is retried.');
        } catch (GatewayException) {
        }

        $this->assertSame([], $this->gateway->voids);
        $this->assertSame('pending', PaymentTransaction::query()->where('idempotency_key', "void:{$this->payment->uuid}:{$id}")->value('status')->value);

        // Meanwhile the hold lapsed at Moyasar: nothing to send, recorded as done.
        $this->gateway->down = false;
        $this->gateway->setStatus((string) $this->payment->provider_payment_id, 'voided');
        app(ManualVoids::class)->execute($request);
        $this->assertSame([], $this->gateway->voids);
        $this->assertSame('voided', $this->payment->refresh()->status->value);

        // The requeue command finds nothing left to send.
        $this->artisan('finance:requeue-approved')->expectsOutputToContain('0 manual voids')->assertSuccessful();
    }

    #[Test]
    public function a_hold_moyasar_already_took_is_not_voided(): void
    {
        $this->gateway->setStatus((string) $this->payment->provider_payment_id, 'captured', $this->payment->authorized_amount_halalas);
        $id = $this->as($this->token(AdminRole::Finance), 'post', "/api/v1/admin/payments/{$this->payment->uuid}/void", ['reason' => 'Orphan hold'])->json('data.id');
        $this->as($this->token(AdminRole::Finance), 'post', "/api/v1/admin/approval-requests/{$id}/approve")->assertOk();

        $this->assertSame([], $this->gateway->voids);
        $attempt = PaymentTransaction::query()->where('idempotency_key', "void:{$this->payment->uuid}:{$id}")->sole();
        $this->assertSame(['failed', 'not_voidable'], [$attempt->status->value, $attempt->error_code]);
        $this->assertSame('failed', $this->payment->refresh()->status->value);
    }

    #[Test]
    public function only_a_hold_that_no_longer_counts_is_released(): void
    {
        [$join] = $this->paidJoin($this->campaign, 2);
        $counting = $join->latestPayment()->firstOrFail();
        $finance = $this->token(AdminRole::Finance);

        $this->as($finance, 'get', "/api/v1/admin/payments/{$counting->uuid}")->assertOk()->assertJsonPath('data.void_requestable', false);
        $this->as($finance, 'post', "/api/v1/admin/payments/{$counting->uuid}/void", ['reason' => 'Release it'])
            ->assertConflict()
            ->assertJsonPath('code', 'not_voidable');

        // A cancelled join's hold waiting for its automatic void may be released by hand.
        $counting->forceFill(['rejection_reason' => 'participation_cancelled'])->save();
        $this->as($finance, 'post', "/api/v1/admin/payments/{$counting->uuid}/void", ['reason' => 'Release it'])->assertCreated();

        $this->as($finance, 'post', "/api/v1/admin/payments/{$this->payment->uuid}/void", ['reason' => 'x'])->assertUnprocessable();
        $this->as($this->token(AdminRole::Support), 'post', "/api/v1/admin/payments/{$this->payment->uuid}/void", ['reason' => 'Release it'])->assertForbidden();
        $this->as($finance, 'post', '/api/v1/admin/payments/00000000-0000-4000-8000-000000000000/void', ['reason' => 'Release it'])->assertNotFound();

        // Approved, but the hold counts again meanwhile (the join was put back): refused.
        $id = $this->as($this->token(AdminRole::SuperAdmin), 'post', "/api/v1/admin/payments/{$this->payment->uuid}/void", ['reason' => 'Release it'])->assertCreated()->json('data.id');
        $this->payment->forceFill(['status' => 'authorized'])->save();
        $this->as($this->token(AdminRole::Finance), 'post', "/api/v1/admin/approval-requests/{$id}/approve")
            ->assertConflict()
            ->assertJsonPath('code', 'not_voidable');
        $this->assertSame([], $this->gateway->voids);
    }

    private function token(AdminRole $role): string
    {
        return $this->adminWithToken($role)[1];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function as(string $token, string $method, string $uri, array $body = []): TestResponse
    {
        $this->forgetAuthenticatedUsers();
        $response = $this->withToken($token)->json(strtoupper($method), $uri, $body);
        $this->forgetAuthenticatedUsers();

        return $response;
    }
}
