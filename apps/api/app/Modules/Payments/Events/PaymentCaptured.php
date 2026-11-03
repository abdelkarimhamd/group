<?php

namespace App\Modules\Payments\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A hold was captured at the final price. Fired after commit. The order,
 * its tax invoice and the ledger posting are made in the transaction that
 * records the capture (App\Modules\Orders\Services\Orders).
 */
final class PaymentCaptured implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $paymentId) {}
}
