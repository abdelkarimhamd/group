<?php

namespace Tests\Feature\Identity;

use App\Models\AuditLog;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\OtpChallenge;
use App\Modules\Identity\Permissions\AdminPermissions;
use App\Modules\Notifications\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Fakes\FakeSmsSender;
use Tests\TestCase;

class AdminRolesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_role_is_defined_and_every_granted_permission_exists(): void
    {
        $roles = AdminPermissions::roles();

        $this->assertEqualsCanonicalizing(array_column(AdminRole::cases(), 'value'), array_keys($roles));

        foreach ($roles as $role => $permissions) {
            $this->assertSame([], array_diff($permissions, array_keys(AdminPermissions::ALL)), "{$role} grants an unknown permission.");
        }
    }

    #[Test]
    public function only_finance_can_release_or_adjust_money(): void
    {
        foreach (AdminPermissions::roles() as $role => $permissions) {
            $held = array_intersect(AdminPermissions::FINANCE_ONLY, $permissions);

            $role === AdminRole::Finance->value
                ? $this->assertEqualsCanonicalizing(AdminPermissions::FINANCE_ONLY, $held)
                : $this->assertSame([], array_values($held), "{$role} holds a finance-only permission.");
        }
    }

    #[Test]
    public function syncing_is_repeatable_and_removes_retired_permissions(): void
    {
        Permission::findOrCreate('retired.permission', 'admin');

        $this->artisan('admin:sync-roles')->assertSuccessful();
        $this->artisan('admin:sync-roles')->assertSuccessful();

        $this->assertSame(count(AdminPermissions::ALL), Permission::where('guard_name', 'admin')->count());
        $this->assertSame(count(AdminRole::cases()), Role::where('guard_name', 'admin')->count());
        $this->assertNull(Permission::where('name', 'retired.permission')->first());
        $this->assertEqualsCanonicalizing(
            AdminPermissions::roles()['support'],
            Role::findByName('support', 'admin')->permissions->pluck('name')->all(),
        );
    }

    #[Test]
    public function admin_create_makes_an_admin_who_can_sign_in(): void
    {
        $this->artisan('admin:create', ['email' => 'Sara@Example.com', '--name' => 'Sara', '--phone' => '0512345678', '--role' => ['finance', 'support']])
            ->expectsQuestion('Password (at least 12 characters)', 'correct horse battery')
            ->assertSuccessful();

        $admin = AdminUser::sole();
        $this->assertSame('sara@example.com', $admin->email);
        $this->assertSame('+966512345678', $admin->phone_e164);
        $this->assertTrue(Hash::check('correct horse battery', $admin->password));
        $this->assertEqualsCanonicalizing(['finance', 'support'], $admin->getRoleNames()->all());

        $audit = AuditLog::where('action', 'admin.create')->sole();
        $this->assertSame('system', $audit->actor_type);
        $this->assertSame($admin->id, $audit->subject_id);

        $this->app->instance(SmsSender::class, new FakeSmsSender);
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'sara@example.com', 'password' => 'correct horse battery'])->assertStatus(202);
        $this->assertSame(1, OtpChallenge::count());
    }

    #[Test]
    public function admin_create_refuses_bad_input(): void
    {
        $this->artisan('admin:create', ['email' => 'sara@example.com', '--name' => 'Sara', '--phone' => '0112345678', '--role' => ['owner']])
            ->expectsQuestion('Password (at least 12 characters)', 'short')
            ->expectsOutputToContain('Saudi mobile')
            ->assertFailed();

        $this->assertSame(0, AdminUser::count());

        AdminUser::factory()->create(['email' => 'taken@example.com']);
        $this->artisan('admin:create', ['email' => 'taken@example.com', '--name' => 'Sara', '--phone' => '0512345678', '--role' => ['finance']])
            ->expectsQuestion('Password (at least 12 characters)', 'correct horse battery')
            ->assertFailed();
    }

    #[Test]
    public function old_sign_in_codes_are_pruned(): void
    {
        $admin = AdminUser::factory()->create();
        $make = fn (int $daysAgo) => OtpChallenge::forceCreate([
            'uuid' => (string) Str::uuid(),
            'purpose' => 'admin_login',
            'phone_e164' => $admin->phone_e164,
            'admin_user_id' => $admin->id,
            'code_hash' => str_repeat('0', 64),
            'expires_at' => now()->subDays($daysAgo),
            'created_at' => now()->subDays($daysAgo),
            'updated_at' => now()->subDays($daysAgo),
        ]);
        $old = $make(31);
        $recent = $make(29);

        $this->artisan('model:prune', ['--model' => [OtpChallenge::class]])->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNotNull($recent->fresh());
    }
}
