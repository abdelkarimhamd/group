<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\MoyasarSettlement;
use App\Modules\Finance\Models\MoyasarSettlementLine;
use App\Modules\Payments\Gateway\GatewayException;
use App\Modules\Payments\Gateway\PaymentReports;
use App\Modules\Payments\Gateway\ProviderSettlement;
use App\Modules\Payments\Gateway\ProviderSettlementLine;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Support\Actor;
use App\Support\Money\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Moyasar's settlements to the bank (spec, E15: fees and payouts in the
 * ledger). `payments:settlements` reads Moyasar's list, newest first, and
 * imports each settlement it has not seen: its lines are matched to our
 * payments, and one ledger transaction moves the gross out of Moyasar
 * clearing into the bank, Moyasar's fees and the VAT on them. Each
 * settlement is imported once, with its posting, in one transaction.
 *
 * Moyasar's settlement API is read as documented; check it against the
 * sandbox before launch (field names of the settlement object itself are
 * not documented, so the amounts come from its lines).
 */
final class Settlements
{
    public function __construct(
        private readonly PaymentReports $reports,
        private readonly Ledger $ledger,
    ) {}

    /**
     * Import the settlements not seen yet. Moyasar lists newest first, so
     * reading stops at the first page with nothing new (or after
     * $maxPages, for the very first import).
     *
     * @return array{imported: int, seen: int}
     *
     * @throws GatewayException when Moyasar cannot be reached
     */
    public function import(int $maxPages = 50): array
    {
        if (app('db.transactions')->callbackApplicableTransactions()->isNotEmpty()) {
            throw new LogicException('Read Moyasar outside any database transaction.');
        }

        $imported = 0;
        $seen = 0;
        $page = 1;

        while ($page !== null && $page <= $maxPages) {
            $list = $this->reports->settlements($page);
            $known = MoyasarSettlement::query()
                ->whereIn('provider_settlement_id', array_map(fn (ProviderSettlement $s) => $s->id, $list['items']))
                ->pluck('provider_settlement_id')
                ->all();
            $new = array_values(array_filter($list['items'], fn (ProviderSettlement $s) => ! in_array($s->id, $known, true)));
            $seen += count($list['items']);

            foreach ($new as $settlement) {
                $imported += $this->importOne($settlement) ? 1 : 0;
            }

            $page = $new === [] ? null : $list['next_page'];
        }

        return ['imported' => $imported, 'seen' => $seen];
    }

    /**
     * Returns false when another run imported it first.
     *
     * @throws GatewayException
     */
    private function importOne(ProviderSettlement $settlement): bool
    {
        if ($settlement->currency !== 'SAR') {
            Log::critical('payments.settlement.currency', ['settlement' => $settlement->id, 'currency' => $settlement->currency]);

            return false;
        }

        $lines = [];
        $page = 1;

        while ($page !== null) {
            $list = $this->reports->settlementLines($settlement->id, $page);
            array_push($lines, ...$list['items']);
            $page = $list['next_page'];
        }

        try {
            return DB::transaction(fn (): bool => $this->store($settlement, $lines));
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * @param  list<ProviderSettlementLine>  $lines
     */
    private function store(ProviderSettlement $settlement, array $lines): bool
    {
        if (MoyasarSettlement::query()->where('provider_settlement_id', $settlement->id)->exists()) {
            return false;
        }

        $ids = array_values(array_unique(array_filter(array_map(fn (ProviderSettlementLine $l) => $l->paymentId, $lines))));
        $payments = PaymentAuthorization::query()->whereIn('provider_payment_id', $ids)->pluck('id', 'provider_payment_id')->all();

        $net = Money::zero();
        $fee = Money::zero();
        $vat = Money::zero();
        $unmatched = 0;

        foreach ($lines as $line) {
            $net = $net->plus(Money::sar($line->amountHalalas));
            $fee = $fee->plus(Money::sar($line->feeHalalas));
            $vat = $vat->plus(Money::sar($line->taxHalalas));

            if ($line->paymentId !== null && ! isset($payments[$line->paymentId])) {
                $unmatched++;
            }
        }

        $gross = $net->plus($fee);
        $posting = $gross->isZero() && $fee->isZero() && $net->isZero()
            ? null
            : $this->ledger->postSettlement($settlement->id, $gross, $net, $fee, $vat, Actor::system());

        $row = MoyasarSettlement::create([
            'provider_settlement_id' => $settlement->id,
            'currency' => 'SAR',
            'gross_halalas' => $gross->minor,
            'fee_halalas' => $fee->minor,
            'fee_vat_halalas' => $vat->minor,
            'net_halalas' => $net->minor,
            'settled_at' => $settlement->settledAt,
            'line_count' => count($lines),
            'unmatched_count' => $unmatched,
            'raw' => $settlement->raw,
            'ledger_transaction_id' => $posting?->id,
        ]);

        foreach ($lines as $line) {
            MoyasarSettlementLine::create([
                'moyasar_settlement_id' => $row->id,
                'type' => $line->type,
                'provider_payment_id' => $line->paymentId,
                'payment_authorization_id' => $line->paymentId === null ? null : ($payments[$line->paymentId] ?? null),
                'amount_halalas' => $line->amountHalalas,
                'fee_halalas' => $line->feeHalalas,
                'fee_vat_halalas' => $line->taxHalalas,
                'payment_amount_halalas' => $line->paymentAmountHalalas,
                'transacted_at' => $line->transactedAt,
                'raw' => $line->raw,
            ]);
        }

        if ($unmatched > 0) {
            Log::warning('payments.settlement.unmatched_lines', ['settlement' => $settlement->id, 'count' => $unmatched]);
        }

        return true;
    }
}
