<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\StatusTransition;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Finance\Enums\LedgerAccountCode;
use App\Modules\Finance\Models\ApprovalRequest;
use App\Modules\Finance\Models\LedgerAdjustment;
use App\Modules\Finance\Models\LedgerTransaction;
use App\Modules\Finance\Services\Ledger;
use App\Modules\Identity\Enums\AdminRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ClosesCampaigns;
use Tests\Concerns\RunsAsAppRole;
use Tests\TestCase;

/**
 * Hand-made ledger postings (spec, E15: a manual adjustment needs a second
 * admin). Nothing reaches the ledger before finance approves; mistakes are
 * reversed, never changed.
 */
class LedgerAdjustmentsTest extends TestCase
{
    use ClosesCampaigns, RefreshDatabase, RunsAsAppRole;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->campaign = $this->closableCampaign();
        $this->paidJoin($this->campaign, 3);
        $this->captureAll($this->campaign);
    }

    #[Test]
    public function balanced_lines_are_posted_once_a_second_admin_approves(): void
    {
        [$requester, $token] = $this->adminWithToken(AdminRole::Finance);
        $lines = [
            ['account' => 'payment_fees', 'direction' => 'D', 'amount_halalas' => 1500],
            ['account' => 'bank', 'direction' => 'C', 'amount_halalas' => 1500],
        ];

        $adjustment = $this->as($token, 'post', '/api/v1/admin/ledger/adjustments', ['description' => 'Bank charge for October', 'lines' => $lines])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.lines.0.account_name', 'Payment processing fees')
            ->assertJsonPath('data.lines.1.amount.amount_minor', 1500)
            ->assertJsonPath('data.reverses', null)
            ->assertJsonPath('data.ledger_transaction_id', null)
            ->assertJsonPath('data.requested_by.id', $requester->uuid)
            ->json('data');
        $approval = ApprovalRequest::query()->where('uuid', $adjustment['approval_request_id'])->sole();
        $this->assertSame(['ledger_adjustment', 'Bank charge for October', 1500], [$approval->type->value, $approval->payload['description'], $approval->payload['total_halalas']]);
        $this->assertSame(0, $this->balance(LedgerAccountCode::Bank));

        // The requester cannot approve it.
        $this->as($token, 'post', "/api/v1/admin/approval-requests/{$approval->uuid}/approve")->assertForbidden();
        $this->as($this->token(AdminRole::Finance), 'post', "/api/v1/admin/approval-requests/{$approval->uuid}/approve")->assertOk();

        $posting = LedgerTransaction::query()->where('reference', "adjustment:{$adjustment['id']}")->sole();
        $this->assertSame(['adjustment', $approval->id], [$posting->type->value, $posting->approval_request_id]);
        $this->assertSame(-1500, $this->balance(LedgerAccountCode::Bank));
        $this->assertSame(1500, $this->balance(LedgerAccountCode::PaymentFees));
        $this->assertSame(1, AuditLog::query()->where('action', 'ledger.post')->count());
        $this->assertSame(['pending', 'posted'], StatusTransition::query()->where('subject_type', 'ledger_adjustment')->orderBy('id')->pluck('to_status')->all());

        $finance = $this->token(AdminRole::Finance);
        $this->as($finance, 'get', '/api/v1/admin/ledger/adjustments?status=posted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ledger_transaction_id', $posting->uuid);
        $this->as($finance, 'get', '/api/v1/admin/ledger/transactions?account=bank')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $posting->uuid)
            ->assertJsonPath('data.0.approval_request_id', $approval->uuid)
            ->assertJsonPath('data.0.entries.1.direction', 'C')
            ->assertJsonPath('data.0.actor.type', 'admin');
        $accounts = collect($this->as($finance, 'get', '/api/v1/admin/ledger/accounts')->assertOk()->json('data'))->keyBy('code');
        $this->assertSame([0, 1500, -1500], [$accounts['bank']['debits']['amount_minor'], $accounts['bank']['credits']['amount_minor'], $accounts['bank']['balance']['amount_minor']]);
        $this->assertSame('asset', $accounts['vat_input_recoverable']['type']);
    }

    #[Test]
    public function only_balanced_lines_are_accepted(): void
    {
        $finance = $this->token(AdminRole::Finance);
        $post = fn (array $body) => $this->as($finance, 'post', '/api/v1/admin/ledger/adjustments', ['description' => 'Fix'] + $body);

        $post(['lines' => [
            ['account' => 'bank', 'direction' => 'D', 'amount_halalas' => 100],
            ['account' => 'payment_fees', 'direction' => 'C', 'amount_halalas' => 99],
        ]])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $post(['lines' => [['account' => 'bank', 'direction' => 'D', 'amount_halalas' => 100]]])->assertUnprocessable()->assertJsonValidationErrors('lines');
        $post(['lines' => [
            ['account' => 'cash_drawer', 'direction' => 'D', 'amount_halalas' => 100],
            ['account' => 'bank', 'direction' => 'C', 'amount_halalas' => 100],
        ]])->assertUnprocessable()->assertJsonValidationErrors('lines.0.account');
        $post([])->assertUnprocessable();
        $post(['lines' => [
            ['account' => 'bank', 'direction' => 'D', 'amount_halalas' => 100],
            ['account' => 'payment_fees', 'direction' => 'C', 'amount_halalas' => 100],
        ], 'reverses_transaction_id' => LedgerTransaction::query()->firstOrFail()->uuid])->assertUnprocessable();
        $this->assertSame(0, LedgerAdjustment::count());
    }

    #[Test]
    public function a_posting_is_reversed_at_most_once(): void
    {
        $capture = LedgerTransaction::query()->where('type', 'capture')->firstOrFail();
        $clearing = $this->balance(LedgerAccountCode::MoyasarClearing);
        $this->assertGreaterThan(0, $clearing);
        $finance = $this->token(AdminRole::Finance);
        $reverse = fn (string $id) => $this->as($finance, 'post', '/api/v1/admin/ledger/adjustments', ['description' => 'Posted twice by mistake', 'reverses_transaction_id' => $id]);

        // Declined: the posting can be asked for again.
        $first = $reverse($capture->uuid)->assertCreated()->assertJsonPath('data.lines', null)->assertJsonPath('data.reverses.id', $capture->uuid)->json('data');
        $reverse($capture->uuid)->assertConflict()->assertJsonPath('code', 'already_reversed');
        $this->as($this->token(AdminRole::Finance), 'post', "/api/v1/admin/approval-requests/{$first['approval_request_id']}/reject", ['reason' => 'It was right'])->assertOk();
        $this->assertSame('rejected', LedgerAdjustment::query()->where('uuid', $first['id'])->value('status')->value);

        $second = $reverse($capture->uuid)->assertCreated()->json('data');
        $this->as($this->token(AdminRole::Finance), 'post', "/api/v1/admin/approval-requests/{$second['approval_request_id']}/approve")->assertOk();
        $reversal = LedgerTransaction::query()->where('reverses_transaction_id', $capture->id)->sole();
        $this->assertSame('reversal', $reversal->type->value);
        $this->assertSame($clearing - $capture->entries()->where('direction', 'D')->whereHas('account', fn ($q) => $q->where('code', 'moyasar_clearing'))->sum('amount_halalas'), $this->balance(LedgerAccountCode::MoyasarClearing));

        $reverse($capture->uuid)->assertConflict()->assertJsonPath('code', 'already_reversed');
        $reverse($reversal->uuid)->assertConflict()->assertJsonPath('code', 'invalid_transition');
        $reverse('00000000-0000-4000-8000-000000000000')->assertNotFound();
        $this->as($finance, 'get', '/api/v1/admin/ledger/transactions?type=reversal')
            ->assertOk()
            ->assertJsonPath('data.0.reverses_transaction_id', $capture->uuid);
    }

    #[Test]
    public function a_reversal_works_with_production_grants_on_the_ledger(): void
    {
        // The ledger is append-only: production's app role cannot row-lock it.
        $capture = LedgerTransaction::query()->where('type', 'capture')->firstOrFail();
        $finance = $this->token(AdminRole::Finance);
        $approver = $this->token(AdminRole::Finance);
        $this->runAsAppRole();

        $request = $this->as($finance, 'post', '/api/v1/admin/ledger/adjustments', ['description' => 'Posted twice by mistake', 'reverses_transaction_id' => $capture->uuid])
            ->assertCreated()
            ->json('data');
        $this->as($approver, 'post', "/api/v1/admin/approval-requests/{$request['approval_request_id']}/approve")->assertOk();

        $this->assertSame('reversal', LedgerTransaction::query()->where('reverses_transaction_id', $capture->id)->sole()->type->value);
    }

    #[Test]
    public function the_ledger_needs_its_permissions(): void
    {
        $body = ['description' => 'Fix', 'lines' => [
            ['account' => 'bank', 'direction' => 'D', 'amount_halalas' => 100],
            ['account' => 'payment_fees', 'direction' => 'C', 'amount_halalas' => 100],
        ]];

        foreach ([AdminRole::Support, AdminRole::Operations, AdminRole::CampaignManager] as $role) {
            $this->as($this->token($role), 'get', '/api/v1/admin/ledger/accounts')->assertForbidden();
            $this->as($this->token($role), 'post', '/api/v1/admin/ledger/adjustments', $body)->assertForbidden();
        }

        // A super admin may ask, but only finance decides.
        $id = $this->as($this->token(AdminRole::SuperAdmin), 'post', '/api/v1/admin/ledger/adjustments', $body)->assertCreated()->json('data.approval_request_id');
        $this->as($this->token(AdminRole::SuperAdmin), 'post', "/api/v1/admin/approval-requests/{$id}/approve")->assertForbidden();
        $this->as($this->token(AdminRole::Finance), 'get', '/api/v1/admin/ledger/transactions')->assertOk();
    }

    private function balance(LedgerAccountCode $account): int
    {
        return app(Ledger::class)->balance($account)->minor;
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
