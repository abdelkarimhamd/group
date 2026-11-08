<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Modules\Identity\Erasure\AccountErasure;
use App\Modules\Identity\Http\Requests\PrivacyConsentRequest;
use App\Modules\Identity\Http\Requests\UpdateProfileRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\PrivacyConsent;
use App\Modules\Identity\Models\User;
use App\Support\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The signed-in customer's own profile, privacy consent and account deletion.
 */
final class AccountController extends Controller
{
    public function show(Request $request): UserResource
    {
        return UserResource::make($this->customer($request)->load('latestPrivacyConsent'));
    }

    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $this->customer($request);
        $user->fill($request->validated())->save();

        return UserResource::make($user->load('latestPrivacyConsent'));
    }

    /**
     * Records consent to the current policy version. Accepting a version the
     * customer already accepted is a no-op, so retries never add rows.
     */
    public function acceptPrivacyPolicy(PrivacyConsentRequest $request): UserResource
    {
        $user = $this->customer($request);
        $version = (string) config('groupbuy.account.privacy_policy_version');

        if ($request->validated('policy_version') !== $version) {
            throw ApiException::unprocessable('privacy_policy_outdated', 'policy_version');
        }

        if (! $user->hasAcceptedCurrentPrivacyPolicy()) {
            PrivacyConsent::create([
                'user_id' => $user->id,
                'policy_version' => $version,
                'accepted_at' => now(),
                'ip' => RequestContext::ip(),
                'user_agent' => RequestContext::userAgent(),
                'request_id' => RequestContext::requestId(),
            ]);
        }

        return UserResource::make($user->load('latestPrivacyConsent'));
    }

    /**
     * Deletes the account: personal data is erased and every device is signed
     * out. See AccountErasure for what is kept and what blocks it.
     */
    public function destroy(Request $request, AccountErasure $erasure): Response
    {
        $erasure->erase($this->customer($request));

        return response()->noContent();
    }

    private function customer(Request $request): User
    {
        return $request->user();
    }
}
