<?php

namespace App\Modules\Supplier\Enums;

/**
 * Mirrors the CHECK constraint on supplier_quotations.status. Admins move a
 * quote between draft, accepted and rejected; `quotations:expire` sets expired.
 */
enum QuotationStatus: string
{
    case Draft = 'draft';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
