<?php

namespace App\Modules\Orders\Enums;

/**
 * Reporting to ZATCA (Fatoora phase 2) comes with the integrations; until
 * then every invoice stays `pending`.
 */
enum ZatcaStatus: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
