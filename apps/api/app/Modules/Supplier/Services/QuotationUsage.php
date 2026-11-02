<?php

namespace App\Modules\Supplier\Services;

use App\Modules\Supplier\Models\SupplierQuotation;

/**
 * Which campaigns use a quotation. A quotation is frozen once a campaign past
 * draft uses it, because that campaign's prices and margin were checked
 * against it.
 *
 * Campaigns arrive with E7, which answers these from the Campaign module.
 * Until then no quotation is in use.
 */
class QuotationUsage
{
    /**
     * @return list<string> Campaign UUIDs, oldest first.
     */
    public function campaignIds(SupplierQuotation $quotation): array
    {
        return [];
    }

    public function isFrozen(SupplierQuotation $quotation): bool
    {
        return false;
    }
}
