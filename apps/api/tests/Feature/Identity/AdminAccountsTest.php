<?php

namespace Tests\Feature\Identity;

use App\Models\AuditLog;
use App\Models\StatusTransition;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class AdminAccountsTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private AdminUser $manager;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        [$this->manager, $this->token] = $this->adminWithToken(AdminRole::SuperAdmin);
    }

    #[Test]
    public function admins_are_listed_a_page_at_a_time(): void
    {
        AdminUser::factory()->count(3)->withRole(AdminRole::Support)->create();
        AdminUser::factory()->blocked()->create();

        $first = $this->withToken($this->token)->getJson('/api/v1/admin/admins?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('data.0.id', $this->manager->uuid)
            ->assertJsonPath('data.0.roles', ['super_admin']);

        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $this->withToken($this->token)->getJson('/api/v1/admin/admins?per_page=2&cursor='.urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.permissions', ['approvals.view', 'campaigns.view', 'orders.view', 'payments.view', 'refunds.request']);

        $this->withToken($this->token)->getJson('/api/v1/admin/admins?status=blocked')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'blocked')
            ->assertJsonPath('meta.next_cursor', null);
    }

    #[Test]
    public function an_admin_without_admins_manage_is_forbidden(): void
    {
        [, $financeToken] = $this->adminWithToken(AdminRole::Finance);
        $target = AdminUser::factory()->create();

        $this->withToken($financeToken)->getJson('/api/v1/admin/admins', ['Accept-Language' => 'en'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'You do not have permission to do this.', 'code' => 'forbidden']);
        $this->withToken($financeToken)->postJson('/api/v1/admin/admins', $this->newAdmin())->assertForbidden();
        $this->withToken($financeToken)->patchJson("/api/v1/admin/admins/{$target->uuid}", ['name' => 'x'])->assertForbidden();

        $this->assertSame(3, AdminUser::count());
        $this->assertNotSame('x', $target->fresh()->name);
    }

    #[Test]
    public function a_new_admin_can_sign_in_with_the_given_password(): void
    {
        $response = $this->withToken($this->token)->postJson('/api/v1/admin/admins', $this->newAdmin())
            ->assertCreated()
            ->assertJsonPath('data.email', 'omar@example.com')
            ->assertJsonPath('data.phone', '+966598765432')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.roles', ['operations', 'support'])
            ->assertJsonPath('data.last_login_at', null);

        $admin = AdminUser::where('uuid', $response->json('data.id'))->sole();
        $this->assertTrue(Hash::check('a long enough password', $admin->password));

        $audit = AuditLog::where('action', 'admin.create')->sole();
        $this->assertSame($this->manager->id, $audit->actor_id);
        $this->assertSame('portal', $audit->after['via']);
        $this->assertStringNotContainsString('a long enough password', json_encode($audit->after));

        $this->fakeSms();
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'omar@example.com', 'password' => 'a long enough password'])->assertStatus(202);
    }

    #[Test]
    public function new_admins_are_validated(): void
    {
        AdminUser::factory()->create(['email' => 'taken@example.com']);

        $this->withToken($this->token)->postJson('/api/v1/admin/admins', $this->newAdmin([
            'email' => 'TAKEN@example.com',
            'phone' => '0112345678',
            'roles' => ['owner'],
            'password' => 'short',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'phone', 'roles.0', 'password']);

        $this->withToken($this->token)->postJson('/api/v1/admin/admins', $this->newAdmin(['roles' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('roles');
    }

    #[Test]
    public function roles_and_details_change_with_an_audit_trail(): void
    {
        $target = AdminUser::factory()->withRole(AdminRole::Support)->create(['name' => 'Omar']);

        $this->withToken($this->token)
            ->patchJson("/api/v1/admin/admins/{$target->uuid}", ['name' => 'Omar Ali', 'phone' => '0598765432', 'roles' => ['finance']])
            ->assertOk()
            ->assertJsonPath('data.name', 'Omar Ali')
            ->assertJsonPath('data.phone', '+966598765432')
            ->assertJsonPath('data.roles', ['finance'])
            ->assertJsonPath('data.permissions', fn (array $p) => in_array('approvals.decide', $p, true) && ! in_array('fulfilment.edit', $p, true));

        $audit = AuditLog::where('action', 'admin.update')->sole();
        $this->assertSame(['name' => 'Omar', 'phone' => $target->phone_e164, 'roles' => ['support']], $audit->before);
        $this->assertSame(['name' => 'Omar Ali', 'phone' => '+966598765432', 'roles' => ['finance']], $audit->after);
    }

    #[Test]
    public function blocking_an_admin_signs_them_out_and_records_the_status_change(): void
    {
        [$target, $targetToken] = $this->adminWithToken(AdminRole::Support);

        $this->withToken($this->token)
            ->patchJson("/api/v1/admin/admins/{$target->uuid}", ['status' => 'blocked'])
            ->assertOk()
            ->assertJsonPath('data.status', 'blocked');

        $this->assertSame(0, $target->tokens()->count());

        $transition = StatusTransition::sole();
        $this->assertSame('admin_user', $transition->subject_type);
        $this->assertSame($target->id, $transition->subject_id);
        $this->assertSame('active', $transition->from_status);
        $this->assertSame('blocked', $transition->to_status);
        $this->assertSame($this->manager->id, $transition->actor_id);

        $this->forgetAuthenticatedUsers();
        $this->withToken($targetToken)->getJson('/api/v1/admin/me')->assertUnauthorized();

        // Unblocking does not bring the old session back.
        $this->forgetAuthenticatedUsers();
        $this->withToken($this->token)->patchJson("/api/v1/admin/admins/{$target->uuid}", ['status' => 'active'])->assertOk();
        $this->forgetAuthenticatedUsers();
        $this->withToken($targetToken)->getJson('/api/v1/admin/me')->assertUnauthorized();
        $this->assertSame(2, StatusTransition::count());
    }

    #[Test]
    public function resetting_a_password_signs_the_admin_out(): void
    {
        [$target] = $this->adminWithToken(AdminRole::Support);

        $this->withToken($this->token)
            ->patchJson("/api/v1/admin/admins/{$target->uuid}", ['password' => 'a brand new password'])
            ->assertOk();

        $this->assertTrue(Hash::check('a brand new password', $target->fresh()->password));
        $this->assertSame(0, $target->tokens()->count());
        $this->assertSame(['password_reset' => true, 'signed_out_tokens' => 1], AuditLog::where('action', 'admin.update')->sole()->after);
    }

    #[Test]
    public function nobody_changes_their_own_access(): void
    {
        foreach ([['roles' => ['finance']], ['status' => 'blocked'], ['password' => 'a brand new password']] as $change) {
            $this->withToken($this->token)
                ->patchJson("/api/v1/admin/admins/{$this->manager->uuid}", $change)
                ->assertStatus(422)
                ->assertJsonPath('code', 'cannot_change_own_access');
        }

        $this->withToken($this->token)
            ->patchJson("/api/v1/admin/admins/{$this->manager->uuid}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name');

        $this->assertSame(['super_admin'], $this->manager->fresh()->getRoleNames()->all());
    }

    #[Test]
    public function an_update_needs_a_field_and_a_real_admin(): void
    {
        $target = AdminUser::factory()->create();

        $this->withToken($this->token)->patchJson("/api/v1/admin/admins/{$target->uuid}", [])->assertStatus(422);
        $this->withToken($this->token)->patchJson('/api/v1/admin/admins/0195f8a4-0000-7000-8000-000000000000', ['name' => 'x'])->assertNotFound();
    }

    #[Test]
    public function an_admin_changes_their_own_password_and_other_sessions_end(): void
    {
        $other = $this->manager->createToken('other-browser')->plainTextToken;

        $this->withToken($this->token)
            ->postJson('/api/v1/admin/me/password', ['current_password' => 'password', 'password' => 'a brand new password'])
            ->assertNoContent();

        $this->assertTrue(Hash::check('a brand new password', $this->manager->fresh()->password));

        $this->forgetAuthenticatedUsers();
        $this->withToken($this->token)->getJson('/api/v1/admin/me')->assertOk();
        $this->forgetAuthenticatedUsers();
        $this->withToken($other)->getJson('/api/v1/admin/me')->assertUnauthorized();

        $this->assertSame(1, AuditLog::where('action', 'admin.password_change')->sole()->after['signed_out_tokens']);
    }

    #[Test]
    public function changing_your_password_needs_the_current_one(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/admin/me/password', ['current_password' => 'wrong', 'password' => 'a brand new password'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_credentials')
            ->assertJsonValidationErrors('current_password');

        $this->withToken($this->token)
            ->postJson('/api/v1/admin/me/password', ['current_password' => 'password', 'password' => 'short'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check('password', $this->manager->fresh()->password));
    }

    #[Test]
    public function password_changes_are_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withToken($this->token)->postJson('/api/v1/admin/me/password', ['current_password' => 'wrong-'.$i, 'password' => 'a brand new password']);
        }

        $this->withToken($this->token)
            ->postJson('/api/v1/admin/me/password', ['current_password' => 'password', 'password' => 'a brand new password'])
            ->assertStatus(429);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newAdmin(array $overrides = []): array
    {
        return array_merge([
            'email' => 'Omar@Example.com',
            'name' => 'Omar',
            'phone' => '0598765432',
            'roles' => ['support', 'operations'],
            'password' => 'a long enough password',
        ], $overrides);
    }
}
