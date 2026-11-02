<?php

namespace App\Modules\Pricing;

use App\Support\Money\Money;
use JsonSerializable;

/**
 * The margin check shown on the approval screen: the `MarginReport` schema.
 */
final class MarginReport implements JsonSerializable
{
    public const WARNING_TARGET_BELOW_MOQ = 'min_target_below_supplier_moq';

    public const WARNING_TARGET_IN_LOWEST_TIER = 'target_at_lowest_tier_boundary';

    public const WARNING_QUOTATION_EXPIRES = 'quotation_expires_before_capture';

    /**
     * @param  list<array{tier: Tier, net: Money, margin: Money, margin_bp: int, passes: bool}>  $tiers
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly int $minMarginBp,
        public readonly Money $landedCost,
        public readonly Money $paymentFee,
        public readonly Money $deliveryCost,
        public readonly array $tiers,
        public readonly array $warnings,
    ) {}

    public function passes(): bool
    {
        foreach ($this->tiers as $row) {
            if (! $row['passes']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'min_margin_bp' => $this->minMarginBp,
            'passes' => $this->passes(),
            'landed_cost_per_unit' => $this->landedCost,
            'payment_fee_estimate' => $this->paymentFee,
            'delivery_cost_estimate' => $this->deliveryCost,
            'tiers' => array_map(fn (array $row) => [
                'min_qty' => $row['tier']->minQty,
                'max_qty' => $row['tier']->maxQty,
                'unit_price' => $row['tier']->unitPrice(),
                'net_price' => $row['net'],
                'margin' => $row['margin'],
                'margin_bp' => $row['margin_bp'],
                'passes' => $row['passes'],
            ], $this->tiers),
            'warnings' => $this->warnings,
        ];
    }

    /**
     * A compact form for the audit log.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'passes' => $this->passes(),
            'min_margin_bp' => $this->minMarginBp,
            'landed_cost_per_unit_halalas' => $this->landedCost->minor,
            'tiers' => array_map(fn (array $row) => [
                'min_qty' => $row['tier']->minQty,
                'unit_price_halalas' => $row['tier']->unitPriceHalalas,
                'margin_bp' => $row['margin_bp'],
            ], $this->tiers),
            'warnings' => $this->warnings,
        ];
    }
}
