<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\LedgerAccountCode;
use App\Modules\Finance\Enums\LedgerTransactionType;
use App\Modules\Finance\Models\LedgerAccount;
use App\Modules\Finance\Models\LedgerEntry;
use App\Modules\Finance\Models\LedgerTransaction;
use App\Modules\Orders\Enums\InvoiceType;
use App\Modules\Orders\Models\CustomerOrder;
use App\Modules\Orders\Models\Invoice;
use App\Modules\Payments\Models\PaymentAuthorization;
use App\Modules\Payments\Models\Refund;
use App\Support\Actor;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * The double-entry ledger (spec, E15). Each money event is posted in the
 * transaction that records it, as balanced entries that are never changed
 * (the database refuses an unbalanced transaction at commit, and any
 * update or delete). Posting an event twice returns the first posting.
 *
 *  - capture: debit Moyasar clearing with the amount charged; credit
 *    customer prepayments with the net and output VAT with the VAT of its
 *    tax invoice (the tax point is the invoice, issued at capture).
 *  - delivery: the prepayment left after refunds becomes sales revenue.
 *  - refund: the reverse of its credit note, against revenue once the
 *    order's revenue was recognised, otherwise against the prepayment.
 *
 * Moyasar's fees and settlements to the bank, and supplier payments, are
 * posted once their data arrives (integrations); the accounts exist.
 */
final class Ledger
{
    /** @var array<string, int> */
    private array $accounts = [];

    public function postCapture(CustomerOrder $order, Invoice $invoice, PaymentAuthorization $payment, Actor $actor): LedgerTransaction
    {
        if ($invoice->total_halalas !== $payment->captured_amount_halalas) {
            throw new LogicException("Invoice {$invoice->id} does not match the capture of payment {$payment->id}.");
        }

        return $this->post(LedgerTransactionType::Capture, 'payment:'.$payment->uuid, "Capture for order {$order->order_number}", [
            [LedgerAccountCode::MoyasarClearing, 'D', Money::sar($invoice->total_halalas)],
            [LedgerAccountCode::CustomerPrepayments, 'C', Money::sar($invoice->net_halalas)],
            [LedgerAccountCode::VatOutputPayable, 'C', Money::sar($invoice->vat_halalas)],
        ], $actor, $order->id);
    }

    /**
     * The order was delivered: what customers prepaid for it, less what was
     * refunded, becomes revenue. Returns null when nothing is left.
     */
    public function recogniseRevenue(CustomerOrder $order, Actor $actor): ?LedgerTransaction
    {
        $invoices = Invoice::query()->where('customer_order_id', $order->id)->get();
        $net = Money::zero();

        foreach ($invoices as $invoice) {
            $amount = Money::sar($invoice->net_halalas);
            $net = $invoice->type === InvoiceType::CreditNote ? $net->minus($amount) : $net->plus($amount);
        }

        if (! $net->greaterThan(Money::zero())) {
            return null;
        }

        return $this->post(LedgerTransactionType::RevenueRecognition, 'customer_order:'.$order->uuid, "Revenue for order {$order->order_number}", [
            [LedgerAccountCode::CustomerPrepayments, 'D', $net],
            [LedgerAccountCode::SalesRevenue, 'C', $net],
        ], $actor, $order->id);
    }

    /**
     * A refund that went through, split as its credit note (or, without an
     * order, at the standard VAT rate).
     */
    public function postRefund(Refund $refund, ?CustomerOrder $order, ?Invoice $creditNote, Actor $actor): LedgerTransaction
    {
        $total = Money::sar($refund->amount_halalas);

        if ($creditNote !== null) {
            $net = Money::sar($creditNote->net_halalas);
            $vat = Money::sar($creditNote->vat_halalas);
        } else {
            ['net' => $net, 'vat' => $vat] = $total->splitVatInclusive((int) ($order->vat_rate_bp ?? config('groupbuy.vat_rate_bp')));
        }

        $recognised = $order !== null && LedgerTransaction::query()
            ->where('type', LedgerTransactionType::RevenueRecognition)
            ->where('reference', 'customer_order:'.$order->uuid)
            ->exists();

        return $this->post(LedgerTransactionType::Refund, 'refund:'.$refund->uuid, 'Refund'.($order === null ? '' : " for order {$order->order_number}"), [
            [$recognised ? LedgerAccountCode::SalesRevenue : LedgerAccountCode::CustomerPrepayments, 'D', $net],
            [LedgerAccountCode::VatOutputPayable, 'D', $vat],
            [LedgerAccountCode::MoyasarClearing, 'C', $total],
        ], $actor, $order?->id);
    }

    /**
     * Post one balanced transaction in the caller's database transaction.
     * Zero lines are left out.
     *
     * @param  list<array{LedgerAccountCode, 'C'|'D', Money}>  $lines
     */
    public function post(
        LedgerTransactionType $type,
        string $reference,
        string $description,
        array $lines,
        Actor $actor,
        ?int $customerOrderId = null,
        ?int $approvalRequestId = null,
        ?int $reversesTransactionId = null,
    ): LedgerTransaction {
        if (app('db.transactions')->callbackApplicableTransactions()->isEmpty()) {
            throw new LogicException('Post to the ledger inside the transaction that records the event.');
        }

        $existing = LedgerTransaction::query()->where('type', $type)->where('reference', $reference)->first();

        if ($existing !== null) {
            return $existing;
        }

        $debits = Money::zero();
        $credits = Money::zero();
        $lines = array_values(array_filter($lines, fn (array $line) => ! $line[2]->isZero()));

        foreach ($lines as [, $direction, $amount]) {
            if ($amount->isNegative()) {
                throw new LogicException("Ledger amounts are positive; post {$reference} the other way round.");
            }

            if ($direction === 'D') {
                $debits = $debits->plus($amount);
            } else {
                $credits = $credits->plus($amount);
            }
        }

        if (! $debits->equals($credits) || $debits->isZero()) {
            throw new LogicException("Ledger transaction {$reference} does not balance.");
        }

        $transaction = LedgerTransaction::create([
            'type' => $type,
            'reference' => $reference,
            'description' => mb_substr($description, 0, 255),
            'customer_order_id' => $customerOrderId,
            'approval_request_id' => $approvalRequestId,
            'reverses_transaction_id' => $reversesTransactionId,
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'occurred_at' => CarbonImmutable::now(),
        ]);

        foreach ($lines as [$account, $direction, $amount]) {
            LedgerEntry::create([
                'ledger_transaction_id' => $transaction->id,
                'ledger_account_id' => $this->accountId($account),
                'direction' => $direction,
                'amount_halalas' => $amount->minor,
            ]);
        }

        return $transaction;
    }

    /**
     * An account's balance, debits minus credits.
     */
    public function balance(LedgerAccountCode $account): Money
    {
        $sums = LedgerEntry::query()
            ->where('ledger_account_id', $this->accountId($account))
            ->selectRaw("COALESCE(SUM(amount_halalas) FILTER (WHERE direction = 'D'), 0) AS debits, COALESCE(SUM(amount_halalas) FILTER (WHERE direction = 'C'), 0) AS credits")
            ->first();

        return Money::sar((int) $sums->getAttribute('debits'))->minus(Money::sar((int) $sums->getAttribute('credits')));
    }

    private function accountId(LedgerAccountCode $code): int
    {
        return $this->accounts[$code->value] ??= (int) (LedgerAccount::query()->where('code', $code->value)->value('id')
            ?? throw new LogicException("Ledger account {$code->value} is missing."));
    }
}
