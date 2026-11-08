<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supplier payments in the ledger (E15): a paid purchase order moves its
 * SAR value from the bank to goods in transit.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ledger_transactions DROP CONSTRAINT ledger_transactions_type_check');
        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_type_check CHECK (type IN ('capture', 'refund', 'revenue_recognition', 'adjustment', 'reversal', 'settlement', 'supplier_payment'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ledger_transactions DROP CONSTRAINT ledger_transactions_type_check');
        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_type_check CHECK (type IN ('capture', 'refund', 'revenue_recognition', 'adjustment', 'reversal', 'settlement'))");
    }
};
