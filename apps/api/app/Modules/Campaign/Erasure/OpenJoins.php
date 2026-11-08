<?php

namespace App\Modules\Campaign\Erasure;

use App\Modules\Campaign\Enums\ParticipationStatus;
use App\Modules\Campaign\Models\CampaignParticipation;
use App\Modules\Identity\Erasure\ErasureBlocker;
use App\Modules\Identity\Models\User;

/**
 * A join that waits for payment or holds money on the card still needs the
 * customer: it can become an order with a delivery and an invoice.
 */
final class OpenJoins implements ErasureBlocker
{
    public function blocks(User $user): ?string
    {
        $open = CampaignParticipation::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [ParticipationStatus::PendingPayment, ParticipationStatus::Authorized])
            ->exists();

        return $open ? 'joins' : null;
    }
}
