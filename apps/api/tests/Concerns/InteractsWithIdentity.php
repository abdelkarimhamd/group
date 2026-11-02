<?php

namespace Tests\Concerns;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Sms\SmsSender;
use Tests\Fakes\FakeSmsSender;

trait InteractsWithIdentity
{
    protected function fakeSms(): FakeSmsSender
    {
        $fake = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $fake);

        return $fake;
    }

    /**
     * A customer and a bearer token for them, issued the way sign-in issues it.
     *
     * @return array{User, string}
     */
    protected function customerWithToken(?User $user = null): array
    {
        $user ??= User::factory()->create();
        $token = $user->createToken('PHPUnit', expiresAt: now()->addDays((int) config('groupbuy.auth.customer_token_days')));

        return [$user, $token->plainTextToken];
    }

    /**
     * @return array{AdminUser, string}
     */
    protected function adminWithToken(AdminRole ...$roles): array
    {
        $admin = AdminUser::factory()->withRole(...($roles ?: [AdminRole::Support]))->create();
        $token = $admin->createToken('admin-portal', expiresAt: now()->addHours((int) config('groupbuy.auth.admin_token_hours')));

        return [$admin, $token->plainTextToken];
    }

    /**
     * Auth guards remember the user they resolved. Forget it, so the next request
     * in the same test authenticates from scratch, as a real request would.
     */
    protected function forgetAuthenticatedUsers(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
