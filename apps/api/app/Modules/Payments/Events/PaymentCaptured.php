<?php

namespace App\Modules\Payments\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A hold was captured at the final price. Fired after commit. Customer
 * orders (E11) create the order and the ledger (E15) posts the capture.
 */
final class PaymentCaptured implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $paymentId) {}
}
