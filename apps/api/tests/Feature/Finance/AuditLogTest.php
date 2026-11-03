<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

/**
 * Who did what (spec, Security: audit log), newest first, with subjects
 * and admins named by their public IDs.
 */
class AuditLogTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private AdminUser $manager;

    private User $customer;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-02 09:00:00', 'UTC'));
        $this->manager = AdminUser::factory()->withRole(AdminRole::CampaignManager)->create();
        $this->customer = User::factory()->create();
        $this->campaign = Campaign::factory()->active()->create();

        $audit = app(AuditLogger::class);
        $audit->record(Actor::admin($this->manager->id), 'campaign.approve', $this->campaign, ['status' => 'pending_approval'], ['status' => 'scheduled']);
        $audit->record(Actor::user($this->customer->id), 'participation.cancel', $this->customer);
        $audit->record(Actor::system(), 'campaign.close', $this->campaign);
        // A subject without a public ID is shown by its row ID.
        $audit->record(Actor::system(), 'note.add', AuditLog::query()->firstOrFail());
    }

    #[Test]
    public function entries_come_newest_first_naming_actors_and_subjects_publicly(): void
    {
        $entries = $this->list()->assertOk()->assertJsonCount(4, 'data')->json('data');

        $this->assertSame(['note.add', 'campaign.close', 'participation.cancel', 'campaign.approve'], array_column($entries, 'action'));
        $this->assertSame(['type' => 'system', 'id' => null, 'name' => null], $entries[1]['actor']);
        $this->assertSame(['type' => 'user', 'id' => $this->customer->uuid, 'name' => $this->customer->name], $entries[2]['actor']);
        $this->assertSame(['type' => 'admin', 'id' => $this->manager->uuid, 'name' => $this->manager->name], $entries[3]['actor']);
        $this->assertSame(['campaign', $this->campaign->uuid], [$entries[3]['subject_type'], $entries[3]['subject_id']]);
        $this->assertSame(['audit_log', (string) AuditLog::query()->min('id')], [$entries[0]['subject_type'], $entries[0]['subject_id']]);
        $this->assertSame(['status' => 'pending_approval'], $entries[3]['before']);
        $this->assertSame(['status' => 'scheduled'], $entries[3]['after']);
        $this->assertNull($entries[2]['before']);
        $this->assertSame('2026-11-02T09:00:00Z', $entries[3]['occurred_at']);
    }

    #[Test]
    public function entries_are_filtered_by_subject_admin_and_action(): void
    {
        $this->list(['subject_type' => 'campaign', 'subject_id' => $this->campaign->uuid])
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.action', 'campaign.close');
        $this->list(['subject_type' => 'campaign'])->assertOk()->assertJsonCount(2, 'data');
        $this->list(['actor_admin_id' => $this->manager->uuid])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'campaign.approve');
        $this->list(['action' => 'participation.cancel'])->assertOk()->assertJsonCount(1, 'data');

        // Unknown IDs match nothing, never everything.
        $this->list(['subject_type' => 'campaign', 'subject_id' => '00000000-0000-4000-8000-000000000000'])->assertOk()->assertJsonCount(0, 'data');
        $this->list(['actor_admin_id' => '00000000-0000-4000-8000-000000000000'])->assertOk()->assertJsonCount(0, 'data');

        // Pages follow the cursor.
        $first = $this->list(['per_page' => 3])->assertOk()->assertJsonCount(3, 'data');
        $this->list(['per_page' => 3, 'cursor' => $first->json('meta.next_cursor')])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.action', 'campaign.approve');
    }

    #[Test]
    public function filters_are_validated(): void
    {
        $this->list(['subject_id' => $this->campaign->uuid])->assertUnprocessable()->assertJsonValidationErrors('subject_type');
        $this->list(['subject_type' => 'spaceship'])->assertUnprocessable()->assertJsonValidationErrors('subject_type');
        $this->list(['subject_type' => 'audit_log', 'subject_id' => $this->campaign->uuid])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->list(['subject_type' => 'campaign', 'subject_id' => '42'])->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->list(['actor_admin_id' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('actor_admin_id');
    }

    #[Test]
    public function only_admins_who_may_read_the_audit_log_see_it(): void
    {
        $this->list([], AdminRole::Support)->assertForbidden();
        $this->list([], AdminRole::Operations)->assertForbidden();
        $this->list([], AdminRole::SuperAdmin)->assertOk();
        $this->withoutToken()->getJson('/api/v1/admin/audit-logs')->assertUnauthorized();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function list(array $query = [], AdminRole $role = AdminRole::Finance): TestResponse
    {
        [, $token] = $this->adminWithToken($role);
        $this->forgetAuthenticatedUsers();
        $response = $this->withToken($token)->getJson('/api/v1/admin/audit-logs?'.http_build_query($query));
        $this->forgetAuthenticatedUsers();

        return $response;
    }
}
