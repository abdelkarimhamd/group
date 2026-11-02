<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Http\Requests\AdminLoginRequest;
use App\Modules\Identity\Http\Requests\AdminVerifyRequest;
use App\Modules\Identity\Http\Resources\AdminUserResource;
use App\Modules\Identity\Http\Resources\OtpChallengeResource;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\OtpChallenge;
use App\Modules\Identity\Otp\OtpService;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

        // Check a password even when the email is unknown, so response time
        // does not reveal which emails belong to admins.
        $passwordMatches = Hash::check($request->validated('password'), $admin->password ?? $this->dummyHash());

        if ($admin === null || ! $passwordMatches) {
            Log::notice('Admin sign-in refused', ['reason' => 'invalid_credentials', 'admin_id' => $admin?->id]);

            throw ApiException::unprocessable('invalid_credentials', 'email');
        }

        // Only someone who knows the password learns that the account is blocked.
        if (! $admin->isActive()) {
            Log::notice('Admin sign-in refused', ['reason' => 'account_blocked', 'admin_id' => $admin->id]);

            throw ApiException::unprocessable('account_blocked');
        }

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

    /**
     * A bcrypt hash of a random string, made with the current cost settings so
     * checking against it takes as long as checking a real admin's password.
     */
    private function dummyHash(): string
    {
        return Cache::rememberForever('auth:admin-login:dummy-hash:'.config('hashing.bcrypt.rounds'), fn () => Hash::make(Str::random(40)));
    }
}
