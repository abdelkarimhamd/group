<?php

namespace App\Modules\Orders\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Human references from database sequences, so a number is never reused
 * (a rolled-back transaction only leaves a gap). Months and years are
 * Riyadh time, like campaign codes.
 */
final class DocumentNumbers
{
    /** ORD-2611-000123 */
    public function order(): string
    {
        return sprintf('ORD-%s-%06d', $this->now()->format('ym'), $this->next('customer_order_number_seq'));
    }

    /** INV-2026-000123 for a tax invoice, CRN-2026-000124 for a credit note: one counter for both. */
    public function invoice(bool $creditNote = false): string
    {
        return sprintf('%s-%s-%06d', $creditNote ? 'CRN' : 'INV', $this->now()->format('Y'), $this->next('invoice_number_seq'));
    }

    private function next(string $sequence): int
    {
        return (int) DB::selectOne("SELECT nextval('{$sequence}') AS n")->n;
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::now((string) config('groupbuy.display_timezone'));
    }
}
