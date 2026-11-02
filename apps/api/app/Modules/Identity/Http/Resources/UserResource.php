<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `User` schema in openapi/v1.yaml.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $consent = $this->resource->latestPrivacyConsent;

        return [
            'id' => $this->uuid,
            'phone' => $this->phone_e164,
            'name' => $this->name,
            'email' => $this->email,
            'locale' => $this->locale,
            'privacy_consent' => $consent === null ? null : [
                'policy_version' => $consent->policy_version,
                'accepted_at' => $consent->accepted_at->toIso8601ZuluString(),
            ],
            'current_privacy_policy_version' => config('groupbuy.account.privacy_policy_version'),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
