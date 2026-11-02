<?php

namespace App\Modules\Payments\Gateway;

/**
 * The provider has no payment with that ID. Unlike a plain GatewayException
 * (an outage or an unclear answer), retrying will not help.
 */
class ProviderPaymentNotFound extends GatewayException {}
