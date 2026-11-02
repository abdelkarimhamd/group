<?php

namespace App\Modules\Pricing;

use App\Exceptions\ApiException;
use App\Modules\Campaign\Models\CampaignPriceTier;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * One version of a campaign's price tiers, lowest quantity first.
 *
 * Rules from the spec's Pricing rules: tiers cover 1 to the campaign
 * maximum with no gaps or overlaps, and a tier's price is never higher than
 * the tier before it. The tier for a quantity is the one containing it; the
 * price a joiner holds is the tier for committed quantity + 1.
 */
final class TierSet
{
    /**
     * @param  list<Tier>  $tiers  Sorted by minimum quantity.
     */
    private function __construct(public readonly array $tiers)
    {
        if ($tiers === []) {
            throw new InvalidArgumentException('A tier set needs at least one tier.');
        }
    }

    /**
     * @param  iterable<array{min_qty: int, max_qty: int, unit_price_halalas: int}>  $rows
     */
    public static function fromArray(iterable $rows): self
    {
        $tiers = [];

        foreach ($rows as $row) {
            $tiers[] = new Tier((int) $row['min_qty'], (int) $row['max_qty'], (int) $row['unit_price_halalas']);
        }

        usort($tiers, fn (Tier $a, Tier $b) => [$a->minQty, $a->maxQty] <=> [$b->minQty, $b->maxQty]);

        return new self($tiers);
    }

    /**
     * @param  Collection<int, CampaignPriceTier>  $models
     */
    public static function fromModels(Collection $models): self
    {
        return new self($models
            ->sortBy('min_qty')
            ->map(fn (CampaignPriceTier $tier) => new Tier($tier->min_qty, $tier->max_qty, $tier->unit_price_halalas, $tier->id))
            ->values()
            ->all());
    }

    /**
     * Checks the set covers 1 to $maxQty exactly and prices never rise.
     *
     * @throws ApiException 422 tiers_gap, tiers_overlap, tiers_do_not_reach_max,
     *                      tiers_exceed_max or price_rises_with_quantity
     */
    public function assertValidFor(int $maxQty): void
    {
        $expectedMin = 1;
        $previous = null;

        foreach ($this->tiers as $tier) {
            if ($tier->maxQty < $tier->minQty || $tier->minQty < $expectedMin) {
                throw ApiException::unprocessable('tiers_overlap', 'tiers');
            }

            if ($tier->minQty > $expectedMin) {
                throw ApiException::unprocessable('tiers_gap', 'tiers');
            }

            if ($previous !== null && $tier->unitPriceHalalas > $previous->unitPriceHalalas) {
                throw ApiException::unprocessable('price_rises_with_quantity', 'tiers');
            }

            $expectedMin = $tier->maxQty + 1;
            $previous = $tier;
        }

        if ($previous->maxQty < $maxQty) {
            throw ApiException::unprocessable('tiers_do_not_reach_max', 'tiers');
        }

        if ($previous->maxQty > $maxQty) {
            throw ApiException::unprocessable('tiers_exceed_max', 'tiers');
        }
    }

    /**
     * The tier containing $qty. Past the last tier (a sold-out campaign asks
     * for max + 1) the last tier answers, so the lowest price is shown.
     */
    public function tierFor(int $qty): Tier
    {
        foreach ($this->tiers as $tier) {
            if ($tier->contains($qty)) {
                return $tier;
            }
        }

        return $qty < $this->tiers[0]->minQty ? $this->tiers[0] : $this->lowest();
    }

    /**
     * The tier the next unit sold falls in, given the committed quantity.
     */
    public function current(int $committedQty): Tier
    {
        return $this->tierFor($committedQty + 1);
    }

    /**
     * The cheapest tier: the last one, since prices never rise.
     */
    public function lowest(): Tier
    {
        return $this->tiers[array_key_last($this->tiers)];
    }

    /**
     * @return list<array{min_qty: int, max_qty: int, unit_price_halalas: int}>
     */
    public function toArray(): array
    {
        return array_map(fn (Tier $tier) => [
            'min_qty' => $tier->minQty,
            'max_qty' => $tier->maxQty,
            'unit_price_halalas' => $tier->unitPriceHalalas,
        ], $this->tiers);
    }
}
