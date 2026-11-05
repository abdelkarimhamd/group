<?php

namespace App\Modules\Fulfillment\Couriers;

use RuntimeException;

/**
 * The courier answered and said no (bad address, account problem): nothing
 * was booked, and sending the same request again will not help.
 */
final class CourierRefused extends RuntimeException {}
