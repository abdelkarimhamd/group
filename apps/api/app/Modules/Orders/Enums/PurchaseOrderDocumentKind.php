<?php

namespace App\Modules\Orders\Enums;

/**
 * What a file kept with a supplier purchase order is.
 */
enum PurchaseOrderDocumentKind: string
{
    case AlibabaInvoice = 'alibaba_invoice';
    case PaymentReceipt = 'payment_receipt';
    case ShippingDocument = 'shipping_document';
    case CustomsDocument = 'customs_document';
    case Other = 'other';
}
