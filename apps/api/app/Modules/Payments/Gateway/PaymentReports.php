<?php

namespace App\Modules\Payments\Gateway;

use Carbon\CarbonImmutable;

/**
 * Moyasar's read-only lists, for finance: the payments made in a period
 * (reconciliation finds the ones we have no record of) and the
 * settlements paid to the bank with their lines (fees and payouts for the
 * ledger). Pages are Moyasar's, newest first.
 */
interface PaymentReports
{
    /**
     * Payments created between $from and $to.
     *
     * @return array{items: list<ProviderPayment>, next_page: int|null}
     *
     * @throws GatewayException when Moyasar cannot be reached or answers badly
     */
    public function payments(CarbonImmutable $from, CarbonImmutable $to, int $page = 1): array;

    /**
     * @return array{items: list<ProviderSettlement>, next_page: int|null}
     *
     * @throws GatewayException
     */
    public function settlements(int $page = 1): array;

    /**
     * @return array{items: list<ProviderSettlementLine>, next_page: int|null}
     *
     * @throws GatewayException
     */
    public function settlementLines(string $settlementId, int $page = 1): array;
}
