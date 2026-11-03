<?php

namespace App\Modules\Notifications\Sms;

/**
 * The provider may have taken the message (it timed out after the request
 * went out, or failed on its side) and keeps nothing we can check against,
 * so sending it again could deliver it twice.
 */
final class SmsOutcomeUnknown extends SmsDeliveryFailed {}
