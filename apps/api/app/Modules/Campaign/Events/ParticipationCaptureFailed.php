<?php

namespace App\Modules\Campaign\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A join's hold could not be captured at close (declined, or past the
 * capture cutoff): it is left out of the order and its hold is voided.
 * Fired after commit. Notifications (E14) tell the customer.
 */
final class ParticipationCaptureFailed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $participationId) {}
}
