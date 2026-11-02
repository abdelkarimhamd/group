<?php

namespace App\Modules\Payments\Gateway;

use RuntimeException;

/**
 * The provider could not be reached, timed out, or does not know the
 * payment. The outcome of the call is unknown: check with the provider
 * before trying again, never retry blind.
 */
class GatewayException extends RuntimeException {}
