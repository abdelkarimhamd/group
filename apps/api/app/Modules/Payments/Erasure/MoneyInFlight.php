<?php

namespace App\Modules\Payments\Erasure;

use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Identity\Erasure\ErasureBlocker;
use App\Modules\Identity\Models\User;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Enums\RefundStatus;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\Refund;

/**
 * A hold still on the card (for example one whose void is queued after the
 * join ended), a payment Moyasar has not answered yet, or a refund on its
 * way back to the card.
 */
final class MoneyInFlight implements ErasureBlocker
{
    public function blocks(User $user): ?string
    {
        $payments = PaymentAuthorization::query()->whereIn(
            'participation_id',
            CampaignParticipation::query()->select('id')->where('user_id', $user->id),
        );

        $holding = (clone $payments)
            ->whereIn('status', [PaymentStatus::PendingAuthorization, PaymentStatus::Authorized])
            ->exists();

        $refunding = Refund::query()
            ->whereIn('payment_authorization_id', $payments->select('id'))
            ->whereIn('status', [RefundStatus::PendingApproval, RefundStatus::Approved, RefundStatus::Processing])
            ->exists();

        return $holding || $refunding ? 'payments' : null;
    }
}
