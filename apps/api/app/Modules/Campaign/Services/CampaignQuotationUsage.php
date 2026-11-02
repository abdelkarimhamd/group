<?php

namespace App\Modules\Campaign\Services;

use App\Modules\Campaign\Enums\CampaignStatus;
use App\Modules\Campaign\Models\Campaign;
use App\Modules\Supplier\Models\SupplierQuotation;
use App\Modules\Supplier\Services\QuotationUsage;

/**
 * Answers the Supplier module's question from the Campaign module: a
 * quotation is frozen once any campaign using it has left draft, because
 * that campaign's prices and margin were (or are being) checked against it.
 */
final class CampaignQuotationUsage extends QuotationUsage
{
    public function campaignIds(SupplierQuotation $quotation): array
    {
        return Campaign::query()
            ->where('supplier_quotation_id', $quotation->id)
            ->orderBy('id')
            ->pluck('uuid')
            ->all();
    }

    public function isFrozen(SupplierQuotation $quotation): bool
    {
        return Campaign::query()
            ->where('supplier_quotation_id', $quotation->id)
            ->where('status', '!=', CampaignStatus::Draft)
            ->exists();
    }
}
