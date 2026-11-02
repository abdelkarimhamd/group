<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\OtpChallenge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The `OtpChallenge` schema in openapi/v1.yaml.
 *
 * @mixin OtpChallenge
 */
class OtpChallengeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'challenge_id' => $this->uuid,
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
            'resend_available_at' => $this->created_at
                ->addSeconds((int) config('groupbuy.auth.otp.resend_seconds'))
                ->toIso8601ZuluString(),
        ];
    }
}
