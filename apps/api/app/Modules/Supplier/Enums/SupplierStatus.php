<?php

namespace App\Modules\Supplier\Enums;

/**
 * Mirrors the CHECK constraint on suppliers.status. Only approved suppliers
 * can back a campaign.
 */
enum SupplierStatus: string
{
    case Prospect = 'prospect';
    case Approved = 'approved';
    case Blocked = 'blocked';
}
