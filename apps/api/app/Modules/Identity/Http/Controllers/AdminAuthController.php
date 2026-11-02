<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Admins\AdminAccounts;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Http\Requests\AdminLoginRequest;
use App\Modules\Identity\Http\Requests\AdminVerifyRequest;
use App\Modules\Identity\Http\Requests\ChangePasswordRequest;
use App\Modules\Identity\Http\Resources\AdminUserResource;
use App\Modules\Identity\Http\Resources\OtpChallengeResource;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\OtpChallenge;
use App\Modules\Identity\Otp\OtpService;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Admin sign-in in two steps: email and password, then the SMS code sent to
 * the admin's phone. Only the second step issues a token.
 */
final class AdminAuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $audit,
    ) {}

    public function login(AdminLoginRequest $request): JsonResponse
    {
        $admin = AdminUser::query()->where('email', mb_strtolower(trim($request->validated('email'))))->first();

        // Check a password even when the email is unknown, and fetch the dummy
        // hash either way, so response time does not reveal which emails
        // belong to admins.
        $dummy = $this->dummyHash();
        $passwordMatches = Hash::check($request->validated('password'), $admin->password ?? $dummy);

        if ($admin === null || ! $passwordMatches) {
            Log::notice('Admin sign-in refused', ['reason' => 'invalid_credentials', 'admin_id' => $admin?->id]);

            throw ApiException::unprocessable('invalid_credentials', 'email');
        }

        // Only someone who knows the password learns that the account is blocked.
        if (! $admin->isActive()) {
            Log::notice('Admin sign-in refused', ['reason' => 'account_blocked', 'admin_id' => $admin->id]);

            throw ApiException::unprocessable('account_blocked');
        }

        $this->limitCodes($admin);

        if (Hash::needsRehash($admin->password)) {
            $admin->password = $request->validated('password');
            $admin->save();
        }

        $challenge = $this->otp->issue(OtpPurpose::AdminLogin, $admin->phone_e164, $admin);

        return OtpChallengeResource::make($challenge)->response()->setStatusCode(202);
    }

    public function verify(AdminVerifyRequest $request): JsonResponse
    {
        [$admin, $token] = $this->otp->verify(
            OtpPurpose::AdminLogin,
            $request->validated('challenge_id'),
            $request->validated('code'),
            function (OtpChallenge $challenge): array {
                $admin = AdminUser::query()->whereKey($challenge->admin_user_id)->lockForUpdate()->first();

                // Blocked between the password step and the code.
                if ($admin === null || ! $admin->isActive()) {
                    throw ApiException::unprocessable('account_blocked');
                }

                $now = now();
                $admin->last_login_at = $now;
                $admin->save();

                $token = $admin->createToken(
                    'admin-portal',
                    expiresAt: $now->addHours((int) config('groupbuy.auth.admin_token_hours')),
                );

                $this->audit->record(Actor::admin($admin->id), 'admin.login', $admin, after: [
                    'token_id' => $token->accessToken->getKey(),
                    'expires_at' => $token->accessToken->expires_at?->toIso8601ZuluString(),
                ]);

                return [$admin, $token->plainTextToken];
            },
        );

        return response()->json(['data' => [
            'token' => $token,
            'token_type' => 'Bearer',
            'admin' => AdminUserResource::make($admin),
        ]]);
    }

    public function logout(Request $request): Response
    {
        /** @var AdminUser $admin */
        $admin = $request->user();
        $token = $admin->currentAccessToken();

        DB::transaction(function () use ($admin, $token): void {
            $this->audit->record(Actor::admin($admin->id), 'admin.logout', $admin, before: ['token_id' => $token->getKey()]);
            $token->delete();
        });

        return response()->noContent();
    }

    public function me(Request $request): AdminUserResource
    {
        return AdminUserResource::make($request->user());
    }

    public function changePassword(ChangePasswordRequest $request, AdminAccounts $accounts): Response
    {
        $accounts->changeOwnPassword(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
        );

        return response()->noContent();
    }

    /**
     * Codes to an admin's phone get the same limits as customer codes
     * (groupbuy.auth.otp): one per resend_seconds and per_phone_per_hour an
     * hour, whatever the caller's IP. Counted only after a correct password,
     * so someone without it cannot use up the admin's codes.
     *
     * @throws ThrottleRequestsException
     */
    private function limitCodes(AdminUser $admin): void
    {
        $otp = config('groupbuy.auth.otp');
        $limits = [
            'admin-otp:resend:'.$admin->id => [1, (int) $otp['resend_seconds']],
            'admin-otp:hour:'.$admin->id => [(int) $otp['per_phone_per_hour'], 3600],
        ];

        foreach ($limits as $key => [$max, $decaySeconds]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                Log::notice('Admin sign-in refused', ['reason' => 'too_many_codes', 'admin_id' => $admin->id]);

                throw new ThrottleRequestsException(headers: [
                    'Retry-After' => RateLimiter::availableIn($key),
                    'X-RateLimit-Limit' => $max,
                    'X-RateLimit-Remaining' => 0,
                ]);
            }
        }

        foreach ($limits as $key => [, $decaySeconds]) {
            RateLimiter::hit($key, $decaySeconds);
        }
    }

    /**
     * A bcrypt hash of a random string, made with the current cost settings so
     * checking against it takes as long as checking a real admin's password.
     */
    private function dummyHash(): string
    {
        return Cache::rememberForever('auth:admin-login:dummy-hash:'.config('hashing.bcrypt.rounds'), fn () => Hash::make(Str::random(40)));
    }
}
