<?php

namespace App\Modules\Pricing;

use App\Exceptions\ApiException;
use App\Modules\Campaign\Models\Campaign;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * The margin check at approval (spec, Pricing rules), per unit in halalas:
 *
 *   net price = tier price without VAT (the invoice rounding rule)
 *   margin    = net price - landed cost - payment fee - delivery cost
 *   margin %  = margin / net price, rounded down to a basis point
 *
 * Every tier must reach the minimum margin, because any participant can end
 * up paying any tier's price. The payment fee is a configured estimate until
 * Moyasar confirms its fees; it is worked out on the highest tier price and
 * applied to every tier, so no tier's fee is underestimated.
 */
final class MarginCalculator
{
    /**
     * @throws ApiException 409 tiers_missing or landed_cost_missing
     */
    public function report(Campaign $campaign): MarginReport
    {
        $campaign->loadMissing(['currentTiers', 'quotation']);
        $tiers = $campaign->tierSet() ?? throw new ApiException(409, 'tiers_missing');
        $landedCost = Money::sar($this->landedCostOf($campaign) ?? throw new ApiException(409, 'landed_cost_missing'));

        $minMarginBp = (int) config('groupbuy.pricing.min_margin_bp');
        $vatRateBp = (int) config('groupbuy.vat_rate_bp');
        $fee = $this->paymentFee($tiers->tiers[0]->unitPriceHalalas);
        $delivery = Money::sar((int) config('groupbuy.pricing.delivery_cost_estimate_halalas'));

        $rows = [];

        foreach ($tiers->tiers as $tier) {
            $net = $tier->unitPrice()->splitVatInclusive($vatRateBp)['net'];
            $margin = $net->minus($landedCost)->minus($fee)->minus($delivery);
            $marginBp = $net->isZero() ? PHP_INT_MIN : self::floorDiv($margin->minor * 10_000, $net->minor);

            $rows[] = [
                'tier' => $tier,
                'net' => $net,
                'margin' => $margin,
                'margin_bp' => $marginBp,
                'passes' => $marginBp >= $minMarginBp,
            ];
        }

        return new MarginReport($minMarginBp, $landedCost, $fee, $delivery, $rows, $this->warnings($campaign, $tiers));
    }

    /**
     * The landed cost per unit the check uses: the snapshot taken at approval,
     * or before that the quotation's current figure. Zero means it was never
     * filled in.
     */
    public function landedCostOf(Campaign $campaign): ?int
    {
        $halalas = $campaign->landed_cost_per_unit_halalas ?? $campaign->quotation->landed_cost_per_unit_halalas;

        return $halalas > 0 ? $halalas : null;
    }

    private function paymentFee(int $unitPriceHalalas): Money
    {
        $bp = (int) config('groupbuy.pricing.payment_fee_bp');
        $fixed = (int) config('groupbuy.pricing.payment_fee_fixed_halalas');

        // Rounded up: an estimate that errs towards a lower margin.
        return Money::sar(intdiv($unitPriceHalalas * $bp + 9_999, 10_000) + $fixed);
    }

    /**
     * @return list<string>
     */
    private function warnings(Campaign $campaign, TierSet $tiers): array
    {
        $warnings = [];

        if ($campaign->min_target_qty < $campaign->quotation->moq) {
            $warnings[] = MarginReport::WARNING_TARGET_BELOW_MOQ;
        }

        // Every successful close would land in the cheapest tier, so the
        // higher tiers only size the holds (the spec's design note).
        if (count($tiers->tiers) > 1 && $campaign->min_target_qty >= $tiers->lowest()->minQty) {
            $warnings[] = MarginReport::WARNING_TARGET_IN_LOWEST_TIER;
        }

        if ($this->quotationExpiresBeforeCapture($campaign)) {
            $warnings[] = MarginReport::WARNING_QUOTATION_EXPIRES;
        }

        return $warnings;
    }

    /**
     * Captures happen after the deadline, within the days kept free before
     * the holds expire. Warn when the quote's validity (end of its last day,
     * Riyadh time) ends before that window does.
     */
    private function quotationExpiresBeforeCapture(Campaign $campaign): bool
    {
        $timezone = (string) config('groupbuy.display_timezone');
        $deadline = $campaign->deadline_at
            ?? CarbonImmutable::now()->max($campaign->starts_at)->addDays((int) config('groupbuy.campaigns.duration_days'));
        $captureWindowEnds = $deadline->addDays((int) config('groupbuy.campaigns.extension_buffer_days'));
        $validUntil = CarbonImmutable::parse($campaign->quotation->valid_until->toDateString(), $timezone)->endOfDay();

        return $validUntil->lessThan($captureWindowEnds);
    }

    private static function floorDiv(int $dividend, int $divisor): int
    {
        $quotient = intdiv($dividend, $divisor);

        return ($dividend % $divisor !== 0 && (($dividend < 0) !== ($divisor < 0))) ? $quotient - 1 : $quotient;
    }
}
