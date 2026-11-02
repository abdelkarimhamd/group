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
            ->patchJson("/api/v1/admin/admins/{$target->uuid}", ['name' => 'Omar Ali', 'phone' => '0598765432', 'roles' => ['operations']])
            ->assertOk()
            ->assertJsonPath('data.name', 'Omar Ali')
            ->assertJsonPath('data.phone', '+966598765432')
            ->assertJsonPath('data.roles', ['operations'])
            ->assertJsonPath('data.permissions', fn (array $p) => in_array('fulfilment.edit', $p, true) && ! in_array('refunds.request', $p, true));

        $audit = AuditLog::where('action', 'admin.update')->sole();
        $this->assertSame(['name' => 'Omar', 'phone' => $target->phone_e164, 'roles' => ['support']], $audit->before);
        $this->assertSame(['name' => 'Omar Ali', 'phone' => '+966598765432', 'roles' => ['operations'], 'signed_out_tokens' => 0], $audit->after);
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
    public function nobody_grants_a_role_with_permissions_they_lack(): void
    {
        // super_admin lacks approvals.decide, which only finance holds.
        $this->withToken($this->token)->postJson('/api/v1/admin/admins', $this->newAdmin(['roles' => ['finance']]), ['Accept-Language' => 'en'])
            ->assertForbidden()
            ->assertExactJson(['message' => 'You can only give roles whose permissions you hold yourself.', 'code' => 'cannot_grant_role']);

        $target = AdminUser::factory()->withRole(AdminRole::Support)->create();
        $this->withToken($this->token)->patchJson("/api/v1/admin/admins/{$target->uuid}", ['roles' => ['support', 'finance']])
            ->assertForbidden()
            ->assertJsonPath('code', 'cannot_grant_role');

        $this->assertSame(2, AdminUser::count());
        $this->assertSame(['support'], $target->fresh()->getRoleNames()->all());
        $this->assertSame(0, AuditLog::count());
    }

    #[Test]
    public function an_admin_with_permissions_you_lack_can_only_be_blocked(): void
    {
        [$finance, $financeToken] = $this->adminWithToken(AdminRole::Finance);

        // Taking over the account (new password, own phone) or changing it in any other way is refused.
        foreach ([['password' => 'attacker chosen password'], ['phone' => '0598765432'], ['name' => 'x'], ['roles' => ['support']], ['status' => 'active']] as $change) {
            $this->withToken($this->token)->patchJson("/api/v1/admin/admins/{$finance->uuid}", $change)
                ->assertForbidden()
                ->assertJsonPath('code', 'cannot_manage_admin');
        }

        $this->assertTrue(Hash::check('password', $finance->fresh()->password));

        // Blocking is always possible, to stop a compromised account quickly.
        $this->withToken($this->token)->patchJson("/api/v1/admin/admins/{$finance->uuid}", ['status' => 'blocked'])
            ->assertOk()
            ->assertJsonPath('data.status', 'blocked');

        $this->forgetAuthenticatedUsers();
        $this->withToken($financeToken)->getJson('/api/v1/admin/me')->assertUnauthorized();

        // Unblocking a finance admin is done on the server.
        $this->forgetAuthenticatedUsers();
        $this->withToken($this->token)->patchJson("/api/v1/admin/admins/{$finance->uuid}", ['status' => 'active'])
            ->assertForbidden()
            ->assertJsonPath('code', 'cannot_manage_admin');

        $this->artisan('admin:update', ['email' => $finance->email, '--status' => 'active'])->assertSuccessful();
        $this->assertSame('active', $finance->fresh()->status->value);
        $this->assertSame('system', AuditLog::where('action', 'admin.update')->latest('id')->first()->actor_type);
    }

    #[Test]
    public function each_admin_has_their_own_phone(): void
    {
        $other = AdminUser::factory()->withRole(AdminRole::Support)->create();
        $target = AdminUser::factory()->withRole(AdminRole::Support)->create();

        $this->withToken($this->token)->postJson('/api/v1/admin/admins', $this->newAdmin(['phone' => $this->manager->phone_e164]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->withToken($this->token)->patchJson("/api/v1/admin/admins/{$target->uuid}", ['phone' => $other->phone_e164])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        // Sending an admin's own current phone is not a conflict.
        $this->withToken($this->token)->patchJson("/api/v1/admin/admins/{$target->uuid}", ['phone' => $target->phone_e164])->assertOk();
    }

    #[Test]
    public function a_password_reset_or_phone_change_cancels_a_sign_in_in_progress(): void
    {
        $sms = $this->fakeSms();
        $target = AdminUser::factory()->withRole(AdminRole::Support)->create(['email' => 'omar@example.com']);

        foreach ([
            ['password', ['password' => 'a brand new password']],
            ['a brand new password', ['phone' => '0598765432']],
        ] as [$password, $change]) {
            $challenge = $this->postJson('/api/v1/admin/auth/login', ['email' => 'omar@example.com', 'password' => $password])
                ->assertStatus(202)
                ->json('data.challenge_id');
            $code = $sms->lastCode();

            $this->withToken($this->token)->patchJson("/api/v1/admin/admins/{$target->uuid}", $change)->assertOk();
            $this->forgetAuthenticatedUsers();

            $this->postJson('/api/v1/admin/auth/verify', ['challenge_id' => $challenge, 'code' => $code])
                ->assertStatus(422)
                ->assertJsonPath('code', 'otp_expired');

            // Past the wait between codes.
            $this->travel((int) config('groupbuy.auth.otp.resend_seconds'))->seconds();
        }

        $this->assertSame(0, $target->tokens()->count());
    }

    #[Test]
    public function changing_your_own_password_cancels_a_sign_in_in_progress(): void
    {
        $sms = $this->fakeSms();
        $challenge = $this->postJson('/api/v1/admin/auth/login', ['email' => $this->manager->email, 'password' => 'password'])
            ->assertStatus(202)
            ->json('data.challenge_id');
        $code = $sms->lastCode();

        $this->withToken($this->token)
            ->postJson('/api/v1/admin/me/password', ['current_password' => 'password', 'password' => 'a brand new password'])
            ->assertNoContent();
        $this->forgetAuthenticatedUsers();

        $this->postJson('/api/v1/admin/auth/verify', ['challenge_id' => $challenge, 'code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('code', 'otp_expired');
    }

    #[Test]
    public function a_tampered_cursor_is_refused(): void
    {
        foreach (['not-a-cursor', base64_encode('{"id":"1 OR 1=1","_pointsToNextItems":true}'), base64_encode('{"email":"a","_pointsToNextItems":true}')] as $cursor) {
            $this->withToken($this->token)->getJson('/api/v1/admin/admins?cursor='.urlencode($cursor))
                ->assertStatus(422)
                ->assertJsonValidationErrors('cursor');
        }
    }

    #[Test]
    public function admin_update_on_the_console_changes_roles_and_refuses_unknown_admins(): void
    {
        $target = AdminUser::factory()->withRole(AdminRole::Support)->create(['email' => 'omar@example.com']);

        $this->artisan('admin:update', ['email' => 'Omar@Example.com', '--role' => ['finance']])->assertSuccessful();
        $this->assertSame(['finance'], $target->fresh()->getRoleNames()->all());

        $this->artisan('admin:update', ['email' => 'nobody@example.com', '--name' => 'x'])->assertFailed();
        $this->artisan('admin:update', ['email' => 'omar@example.com'])->assertFailed();
        $this->artisan('admin:update', ['email' => 'omar@example.com', '--phone' => $this->manager->phone_e164])
            ->expectsOutputToContain('Another admin already uses this phone number.')
            ->assertFailed();
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
