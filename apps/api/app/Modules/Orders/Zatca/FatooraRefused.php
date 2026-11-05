<?php

namespace App\Modules\Orders\Zatca;

use RuntimeException;

/**
 * Fatoora refused an onboarding request (a wrong or used OTP, a CSR it
 * does not accept): sending it again changes nothing.
 */
class FatooraRefused extends RuntimeException {}
