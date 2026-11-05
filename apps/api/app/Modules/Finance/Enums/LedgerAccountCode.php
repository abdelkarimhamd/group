<?php

namespace App\Modules\Finance\Enums;

/**
 * The accounts the code posts to; the migration creates them.
 */
enum LedgerAccountCode: string
{
    case MoyasarClearing = 'moyasar_clearing';
    case Bank = 'bank';
    case InventoryInTransit = 'inventory_in_transit';
    case CustomerPrepayments = 'customer_prepayments';
    case VatOutputPayable = 'vat_output_payable';
    case SupplierPayable = 'supplier_payable';
    case SalesRevenue = 'sales_revenue';
    case PaymentFees = 'payment_fees';
    case VatInputRecoverable = 'vat_input_recoverable';
}
