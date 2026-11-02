<?php

namespace Tests\Feature\Supplier;

use App\Models\AuditLog;
use App\Models\StatusTransition;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class SuppliersTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private AdminUser $sourcing;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        [$this->sourcing, $this->token] = $this->adminWithToken(AdminRole::Sourcing);
    }

    #[Test]
    public function sourcing_adds_a_supplier_as_a_prospect(): void
    {
        $supplier = $this->withToken($this->token)->postJson('/api/v1/admin/suppliers', $this->input(['country' => 'cn']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'prospect')
            ->assertJsonPath('data.country', 'CN')
            ->assertJsonPath('data.reviewed_by', null)
            ->assertJsonPath('data.reviewed_at', null)
            ->json('data');

        $this->withToken($this->token)->getJson("/api/v1/admin/suppliers/{$supplier['id']}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Yongkang Appliance Co.');

        $audit = AuditLog::where('action', 'supplier.create')->sole();
        $this->assertSame($this->sourcing->id, $audit->actor_id);
        $this->assertSame('prospect', $audit->after['status']);
    }

    #[Test]
    public function suppliers_are_validated(): void
    {
        $this->withToken($this->token)->postJson('/api/v1/admin/suppliers', $this->input([
            'alibaba_store_url' => 'not a url', 'country' => 'China', 'quality_rating' => 6,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['alibaba_store_url', 'country', 'quality_rating']);
    }

    #[Test]
    public function editing_replaces_the_details_but_never_the_status(): void
    {
        $supplier = Supplier::factory()->create(['contact_name' => 'Li Wei']);

        $this->withToken($this->token)
            ->patchJson("/api/v1/admin/suppliers/{$supplier->uuid}", $this->input(['name' => 'Renamed Co.', 'status' => 'approved']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Co.')
            ->assertJsonPath('data.contact_name', null)
            ->assertJsonPath('data.status', 'prospect');

        $audit = AuditLog::where('action', 'supplier.update')->sole();
        $this->assertEquals(['name' => $supplier->name, 'alibaba_store_url' => $supplier->alibaba_store_url, 'contact_name' => 'Li Wei'], $audit->before);
    }

    #[Test]
    public function only_admins_with_suppliers_approve_review_a_supplier(): void
    {
        $supplier = Supplier::factory()->create();

        $this->withToken($this->token)
            ->postJson("/api/v1/admin/suppliers/{$supplier->uuid}/review", ['status' => 'approved'])
            ->assertForbidden();

        $this->forgetAuthenticatedUsers();
        [$finance, $financeToken] = $this->adminWithToken(AdminRole::Finance);

        $this->withToken($financeToken)
            ->postJson("/api/v1/admin/suppliers/{$supplier->uuid}/review", ['status' => 'approved', 'quality_rating' => 4, 'review_notes' => 'Factory audit passed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.quality_rating', 4)
            ->assertJsonPath('data.review_notes', 'Factory audit passed')
            ->assertJsonPath('data.reviewed_by', ['type' => 'admin', 'id' => $finance->uuid, 'name' => $finance->name])
            ->assertJsonPath('data.reviewed_at', now()->toIso8601ZuluString());

        // Finance can approve but not edit.
        $this->withToken($financeToken)->patchJson("/api/v1/admin/suppliers/{$supplier->uuid}", $this->input())->assertForbidden();

        $transition = StatusTransition::where('subject_type', 'supplier')->sole();
        $this->assertSame(['prospect', 'approved'], [$transition->from_status, $transition->to_status]);
        $this->assertSame($finance->id, $transition->actor_id);
        $this->assertSame('approved', AuditLog::where('action', 'supplier.review')->sole()->after['status']);

        // Blocking later records the new decision.
        $this->withToken($financeToken)
            ->postJson("/api/v1/admin/suppliers/{$supplier->uuid}/review", ['status' => 'blocked'])
            ->assertOk()
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.quality_rating', 4);
        $this->assertSame(2, StatusTransition::where('subject_type', 'supplier')->count());

        $this->withToken($financeToken)->postJson("/api/v1/admin/suppliers/{$supplier->uuid}/review", ['status' => 'trusted'])->assertStatus(422);
    }

    #[Test]
    public function suppliers_are_listed_by_status(): void
    {
        Supplier::factory()->count(2)->create();
        Supplier::factory()->approved()->create();

        $this->withToken($this->token)->getJson('/api/v1/admin/suppliers?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'approved');

        $this->withToken($this->token)->getJson('/api/v1/admin/suppliers?status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reviewed_by.type', 'admin');

        [, $support] = $this->adminWithToken(AdminRole::Support);
        $this->forgetAuthenticatedUsers();
        $this->withToken($support)->getJson('/api/v1/admin/suppliers')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Yongkang Appliance Co.',
            'alibaba_store_url' => 'https://yongkang.en.alibaba.com',
            'country' => 'CN',
            'contact_channel' => 'Alibaba chat',
        ], $overrides);
    }
}
