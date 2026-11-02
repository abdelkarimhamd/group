<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Http\Requests\RequestOtpRequest;
use App\Modules\Identity\Http\Requests\VerifyOtpRequest;
use App\Modules\Identity\Http\Resources\OtpChallengeResource;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\OtpChallenge;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

/**
 * Customer sign-in: a code by SMS, then a bearer token. The first successful
 * sign-in creates the account.
 */
final class CustomerAuthController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        $challenge = $this->otp->issue(OtpPurpose::CustomerLogin, $request->validated('phone'));

        return OtpChallengeResource::make($challenge)->response()->setStatusCode(202);
    }

    public function verify(VerifyOtpRequest $request): JsonResponse
    {
        [$user, $token, $isNewUser] = $this->otp->verify(
            OtpPurpose::CustomerLogin,
            $request->validated('challenge_id'),
            $request->validated('code'),
            function (OtpChallenge $challenge) use ($request): array {
                // Look up first so a returning customer costs no failed insert;
                // createOrFirst covers two first sign-ins racing each other.
                $user = User::query()->where('phone_e164', $challenge->phone_e164)->first()
                    ?? User::query()->createOrFirst(
                        ['phone_e164' => $challenge->phone_e164],
                        ['locale' => App::getLocale()],
                    );

                if (! $user->isActive()) {
                    throw ApiException::unprocessable('account_blocked');
                }

                $now = now();
                $user->forceFill([
                    'phone_verified_at' => $user->wasRecentlyCreated ? $now : ($user->phone_verified_at ?? $now),
                    'last_login_at' => $now,
                ])->save();

                $token = $user->createToken(
                    $request->validated('device_name'),
                    expiresAt: $now->addDays((int) config('groupbuy.auth.customer_token_days')),
                );

                return [$user, $token->plainTextToken, $user->wasRecentlyCreated];
            },
        );

        $user->load('latestPrivacyConsent');

        return response()->json(['data' => [
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user),
            'is_new_user' => $isNewUser,
        ]]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
