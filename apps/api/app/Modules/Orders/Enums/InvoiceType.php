<?php

namespace App\Modules\Orders\Enums;

enum InvoiceType: string
{
    /** A B2C invoice (ZATCA simplified tax invoice), one per order. */
    case SimplifiedTaxInvoice = 'simplified_tax_invoice';

    /** Issued against an invoice when money is refunded. */
    case CreditNote = 'credit_note';
}
