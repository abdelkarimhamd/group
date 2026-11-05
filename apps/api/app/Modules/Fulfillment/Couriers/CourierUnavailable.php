<?php

namespace App\Modules\Fulfillment\Couriers;

use RuntimeException;

/**
 * No clear answer from the courier (timeout, 5xx, a body we cannot read).
 * A booking may or may not exist on the courier's side.
 */
final class CourierUnavailable extends RuntimeException {}
