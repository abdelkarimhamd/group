<?php

namespace App\Modules\Identity\Otp;

use App\Exceptions\ApiException;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\OtpChallenge;
use App\Modules\Notifications\Sms\SmsDeliveryFailed;
use App\Modules\Notifications\Sms\SmsSender;
use App\Support\Phone\SaudiMobile;
use App\Support\RequestContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Issues and checks the 6-digit sign-in codes sent by SMS, for customers and
 * for the second step of admin sign-in.
 *
 * Only an HMAC of each code is stored, keyed with the app key and bound to the
 * challenge ID. Rate limits per phone and per IP run in front of this class
 * (see AppServiceProvider); this class enforces expiry and the wrong-code limit.
 */
final class OtpService
{
    public function __construct(private readonly SmsSender $sms) {}

    /**
     * Create a challenge and text its code. The row is committed before the SMS
     * is sent, so a slow provider never holds a database transaction open.
     *
     * @throws ApiException sms_unavailable (503) when the SMS could not be sent
     */
    public function issue(OtpPurpose $purpose, string $phone, ?AdminUser $admin = null): OtpChallenge
    {
        $config = config('groupbuy.auth.otp');
        $code = $this->generateCode((int) $config['length']);
        $uuid = (string) Str::uuid();

        $challenge = OtpChallenge::create([
            'uuid' => $uuid,
            'purpose' => $purpose,
            'phone_e164' => $phone,
            'admin_user_id' => $admin?->id,
            'code_hash' => $this->hash($uuid, $code),
            'expires_at' => now()->addMinutes((int) $config['ttl_minutes']),
            'ip' => RequestContext::ip(),
            'user_agent' => RequestContext::userAgent(),
        ]);

        try {
            $this->sms->send($phone, __('messages.sms.otp', [
                'code' => $code,
                'minutes' => $config['ttl_minutes'],
            ]));
        } catch (SmsDeliveryFailed $e) {
            Log::error('Sign-in code SMS failed', [
                'challenge_id' => $challenge->uuid,
                'to' => SaudiMobile::mask($phone),
                'error' => $e->getMessage(),
            ]);

            throw ApiException::unavailable('sms_unavailable');
        }

        return $challenge;
    }

    /**
     * Check a code and, if it is right, run $onSuccess in the same transaction
     * that spends the challenge. A wrong code is counted (and the count
     * committed) before the error is thrown.
     *
     * @template T
     *
     * @param  Closure(OtpChallenge): T  $onSuccess
     * @return T
     *
     * @throws ApiException otp_invalid, otp_expired or otp_attempts_exhausted (422)
     */
    public function verify(OtpPurpose $purpose, string $challengeId, string $code, Closure $onSuccess): mixed
    {
        $maxAttempts = (int) config('groupbuy.auth.otp.max_attempts');

        [$failure, $result] = DB::transaction(function () use ($purpose, $challengeId, $code, $onSuccess, $maxAttempts): array {
            $challenge = OtpChallenge::query()
                ->where('uuid', $challengeId)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            if ($challenge === null || $challenge->consumed_at !== null) {
                return ['otp_invalid', null];
            }

            if ($challenge->attempts >= $maxAttempts) {
                return ['otp_attempts_exhausted', null];
            }

            if (! $challenge->expires_at->isFuture()) {
                return ['otp_expired', null];
            }

            if (! hash_equals($challenge->code_hash, $this->hash($challenge->uuid, $code))) {
                $challenge->attempts++;
                $challenge->save();

                return [$challenge->attempts >= $maxAttempts ? 'otp_attempts_exhausted' : 'otp_invalid', null];
            }

            $challenge->consumed_at = now();
            $challenge->save();

            return [null, $onSuccess($challenge)];
        });

        if ($failure !== null) {
            throw ApiException::unprocessable($failure, 'code');
        }

        return $result;
    }

    private function generateCode(int $length): string
    {
        return str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
    }

    private function hash(string $challengeId, string $code): string
    {
        return hash_hmac('sha256', $challengeId.'|'.$code, (string) config('app.key'));
    }
}
