<?php

namespace App\Modules\Orders\Zatca;

use App\Modules\Orders\Enums\InvoiceType;
use App\Modules\Orders\Models\Invoice;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What one e-invoice says, for the UBL XML: an issued invoice or credit
 * note with its place in the EGS unit's chain, or one of the samples the
 * onboarding sends to ZATCA's compliance checks.
 */
final class ZatcaDocument
{
    public const INVOICE = '388';

    public const CREDIT_NOTE = '381';

    public const DEBIT_NOTE = '383';

    /** Simplified (B2C) tax invoice, no third party, nominal, export, summary or self-billing flags. */
    public const SIMPLIFIED = '0200000';

    /**
     * @param  array<string, mixed>  $seller  the invoice's seller snapshot
     * @param  list<array{description_ar?: string, description_en?: string, qty: int, net_halalas: int, vat_halalas: int, total_halalas: int}>  $lines
     */
    public function __construct(
        public readonly string $number,
        public readonly string $uuid,
        public readonly string $typeCode,
        public readonly CarbonImmutable $issuedAt,
        public readonly array $seller,
        public readonly array $lines,
        public readonly int $netHalalas,
        public readonly int $vatHalalas,
        public readonly int $totalHalalas,
        public readonly int $vatRateBp,
        /** Invoice counter value: this document's place in the EGS unit's chain. */
        public readonly int $icv,
        /** The previous document's hash (PIH), or ZatcaSigner::FIRST_PREVIOUS_HASH. */
        public readonly string $previousHash,
        /** For a credit or debit note: the number of the invoice it corrects. */
        public readonly ?string $billingReference = null,
        /** For a credit or debit note: why it was issued. */
        public readonly ?string $reason = null,
    ) {
        if (! in_array($typeCode, [self::INVOICE, self::CREDIT_NOTE, self::DEBIT_NOTE], true)) {
            throw new InvalidArgumentException("Unknown invoice type code {$typeCode}.");
        }

        if ($typeCode !== self::INVOICE && ($billingReference === null || $reason === null)) {
            throw new InvalidArgumentException('A credit or debit note names the invoice it corrects and why.');
        }

        if ($vatRateBp <= 0 || $lines === []) {
            throw new InvalidArgumentException('Only standard-rated documents with lines are supported.');
        }
    }

    public static function fromInvoice(Invoice $invoice, int $icv, string $previousHash): self
    {
        $invoice->loadMissing('order', 'original');
        $creditNote = $invoice->type === InvoiceType::CreditNote;

        return new self(
            number: $invoice->invoice_number,
            uuid: $invoice->zatca_uuid,
            typeCode: $creditNote ? self::CREDIT_NOTE : self::INVOICE,
            issuedAt: $invoice->issued_at,
            seller: $invoice->seller_snapshot,
            lines: $invoice->lines,
            netHalalas: $invoice->net_halalas,
            vatHalalas: $invoice->vat_halalas,
            totalHalalas: $invoice->total_halalas,
            vatRateBp: $invoice->vat_rate_bp,
            icv: $icv,
            previousHash: $previousHash,
            billingReference: $creditNote ? $invoice->original?->invoice_number : null,
            reason: $creditNote ? 'Refund on order '.$invoice->order->order_number : null,
        );
    }
}
