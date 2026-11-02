<?php

use App\Support\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The chart of accounts. Codes are what the code posts to, so they
        // are written here, not by a seeder.
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->string('type', 16);
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_type_check CHECK (type IN ('asset', 'liability', 'equity', 'revenue', 'expense'))");

        $now = now();
        DB::table('ledger_accounts')->insert(array_map(fn (array $account) => $account + ['created_at' => $now, 'updated_at' => $now], [
            ['code' => 'moyasar_clearing', 'name' => 'Moyasar clearing (captured, not yet settled)', 'type' => 'asset'],
            ['code' => 'bank', 'name' => 'Bank', 'type' => 'asset'],
            ['code' => 'inventory_in_transit', 'name' => 'Goods bought, not yet delivered', 'type' => 'asset'],
            ['code' => 'customer_prepayments', 'name' => 'Customer prepayments (paid, not yet delivered)', 'type' => 'liability'],
            ['code' => 'vat_output_payable', 'name' => 'Output VAT payable', 'type' => 'liability'],
            ['code' => 'supplier_payable', 'name' => 'Supplier payable', 'type' => 'liability'],
            ['code' => 'sales_revenue', 'name' => 'Sales revenue', 'type' => 'revenue'],
            ['code' => 'payment_fees', 'name' => 'Payment processing fees', 'type' => 'expense'],
        ]));

        // One business event, in two or more entries that balance. Rows are
        // never changed: a mistake is corrected by a reversal.
        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 24);
            // What the posting is for, e.g. payment:<uuid>; posting the same
            // event twice is refused.
            $table->string('reference', 100);
            $table->string('description', 255);
            $table->foreignId('customer_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('approval_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reverses_transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['type', 'reference']);
            $table->index(['customer_order_id', 'id']);
        });

        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_type_check CHECK (type IN ('capture', 'refund', 'revenue_recognition', 'adjustment', 'reversal'))");
        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_actor_type_check CHECK (actor_type IN ('system', 'admin', 'user'))");
        // A hand-made adjustment is only posted once a second admin approved it.
        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_adjustment_check CHECK (type <> 'adjustment' OR approval_request_id IS NOT NULL)");
        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_reversal_check CHECK ((type = 'reversal') = (reverses_transaction_id IS NOT NULL))");

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->restrictOnDelete();
            $table->char('direction', 1);
            $table->bigInteger('amount_halalas');
            $table->char('currency', 3)->default('SAR');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['ledger_transaction_id']);
            $table->index(['ledger_account_id', 'id']);
        });

        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_direction_check CHECK (direction IN ('D', 'C'))");
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_amount_check CHECK (amount_halalas > 0)');
        DB::statement("ALTER TABLE ledger_entries ADD CONSTRAINT ledger_entries_currency_check CHECK (currency = 'SAR')");

        // Debits equal credits for every transaction, checked at commit so
        // the entries can be written one by one.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_transaction_balances() RETURNS trigger AS $$
            DECLARE
                debits bigint;
                credits bigint;
            BEGIN
                SELECT COALESCE(SUM(amount_halalas) FILTER (WHERE direction = 'D'), 0),
                       COALESCE(SUM(amount_halalas) FILTER (WHERE direction = 'C'), 0)
                  INTO debits, credits
                  FROM ledger_entries
                 WHERE ledger_transaction_id = NEW.ledger_transaction_id;

                IF debits <> credits OR debits = 0 THEN
                    RAISE EXCEPTION 'Ledger transaction % does not balance: debits %, credits %', NEW.ledger_transaction_id, debits, credits
                        USING ERRCODE = 'check_violation';
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            CREATE CONSTRAINT TRIGGER ledger_entries_balanced
                AFTER INSERT ON ledger_entries
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION ledger_transaction_balances();
            SQL);

        AppendOnly::protect('ledger_transactions');
        AppendOnly::protect('ledger_entries');

        // Daily check of our payments against Moyasar.
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->date('run_date');
            $table->string('provider', 16)->default('moyasar');
            $table->string('status', 16)->default('running');
            $table->unsignedInteger('payments_checked')->default(0);
            $table->unsignedInteger('mismatch_count')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['run_date', 'id']);
        });

        DB::statement("ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_runs_status_check CHECK (status IN ('running', 'matched', 'mismatches', 'failed'))");
        DB::statement("ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_runs_provider_check CHECK (provider = 'moyasar')");
        DB::statement("ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_runs_finished_check CHECK ((status = 'running') = (finished_at IS NULL))");

        // One mismatch found by a run. Resolving it only records what was
        // done; anything that moves money goes through an approval request.
        Schema::create('reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('reconciliation_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_authorization_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('provider_payment_id', 100)->nullable();
            $table->string('mismatch_type', 24);
            $table->jsonb('our_value')->nullable();
            $table->jsonb('provider_value')->nullable();
            $table->foreignId('resolved_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestampsTz();

            $table->index(['reconciliation_run_id', 'id']);
            $table->index(['payment_authorization_id']);
        });

        DB::statement("ALTER TABLE reconciliation_items ADD CONSTRAINT reconciliation_items_type_check CHECK (mismatch_type IN ('status', 'amount', 'missing_locally', 'missing_at_provider', 'orphan_hold', 'stuck_void'))");
        DB::statement('ALTER TABLE reconciliation_items ADD CONSTRAINT reconciliation_items_resolution_check CHECK ((resolved_at IS NULL) = (resolved_by_admin_id IS NULL) AND (resolved_at IS NULL) = (resolution_note IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_items');
        Schema::dropIfExists('reconciliation_runs');
        AppendOnly::release('ledger_entries');
        AppendOnly::release('ledger_transactions');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_balanced ON ledger_entries');
        Schema::dropIfExists('ledger_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_transaction_balances()');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
    }
};
