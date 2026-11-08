<?php

namespace App\Modules\Identity\Erasure;

use App\Exceptions\ApiException;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\OtpChallenge;
use App\Modules\Identity\Models\User;
use App\Modules\Notifications\Services\DeviceTokens;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use Illuminate\Support\Facades\DB;

/**
 * A customer deletes their account (PDPL erasure; both app stores require
 * the option in the app). The account row stays, as `anonymized`, because
 * orders, invoices, payments and the ledger point at it and Saudi tax law
 * keeps them: the name, email and phone number go, and so do saved
 * addresses, sign-in codes, push devices and every access token. The phone
 * number is free again, so signing in with it later starts a new account.
 *
 * Refused while money is held or moving, or an order is not settled, so
 * nobody loses a delivery or a refund by deleting too early.
 */
final class AccountErasure
{
    /** What can stand in the way, in the order the customer is told. */
    public const BLOCKED_BY = ['joins', 'orders', 'payments'];

    /**
     * @param  iterable<ErasureBlocker>  $blockers
     */
    public function __construct(
        private readonly iterable $blockers,
        private readonly DeviceTokens $deviceTokens,
        private readonly StatusHistory $history,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws ApiException 409 account_deletion_blocked, with `blocked_by`
     */
    public function erase(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($user->status !== UserStatus::Active) {
                return;
            }

            // Addresses first: a join being made right now holds its address
            // until it commits, so this waits for it, and the checks below
            // then see it. A join that starts later finds no address.
            $user->addresses()->delete();

            $blockedBy = array_values(array_intersect(self::BLOCKED_BY, array_filter(array_map(
                fn (ErasureBlocker $blocker): ?string => $blocker->blocks($user),
                is_array($this->blockers) ? $this->blockers : iterator_to_array($this->blockers, false),
            ))));

            if ($blockedBy !== []) {
                throw new ApiException(409, 'account_deletion_blocked', extra: ['blocked_by' => $blockedBy]);
            }

            if ($user->phone_e164 !== null) {
                OtpChallenge::query()
                    ->where('purpose', OtpPurpose::CustomerLogin)
                    ->where('phone_e164', $user->phone_e164)
                    ->delete();
            }

            $this->deviceTokens->forget($user);
            $user->tokens()->delete();

            $user->forceFill([
                'status' => UserStatus::Anonymized,
                'anonymized_at' => now(),
                'phone_e164' => null,
                'phone_verified_at' => null,
                'name' => null,
                'email' => null,
            ])->save();

            $this->history->record($user, UserStatus::Active, UserStatus::Anonymized, Actor::user($user->id), 'customer_request');
            $this->audit->record(Actor::user($user->id), 'customer.erase', $user);
        });
    }
}
