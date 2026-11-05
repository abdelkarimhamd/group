<?php

use App\Support\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finance gaps (E15): hand-made ledger adjustments and reversals that wait
 * for a second admin, and Moyasar's settlements to the bank with their fees.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The VAT Moyasar charges on its fees can be reclaimed.
        DB::table('ledger_accounts')->insert([
            'code' => 'vat_input_recoverable',
            'name' => 'Input VAT recoverable (on payment fees)',
            'type' => 'asset',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::statement('ALTER TABLE ledger_transactions DROP CONSTRAINT ledger_transactions_type_check');
        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_type_check CHECK (type IN ('capture', 'refund', 'revenue_recognition', 'adjustment', 'reversal', 'settlement'))");

        // What an admin asked to post by hand. The lines wait here until a
        // second admin with the finance role approves; the posting is the
        // ledger transaction it then points to.
        Schema::create('ledger_adjustments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('description', 255);
            // [{account, direction, amount_halalas}], balanced; null for a reversal.
            $table->jsonb('lines')->nullable();
            $table->foreignId('reverses_transaction_id')->nullable()->constrained('ledger_transactions')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->foreignId('requested_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('ledger_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'id']);
        });

        DB::statement("ALTER TABLE ledger_adjustments ADD CONSTRAINT ledger_adjustments_status_check CHECK (status IN ('pending', 'posted', 'rejected'))");
        DB::statement("ALTER TABLE ledger_adjustments ADD CONSTRAINT ledger_adjustments_posted_check CHECK ((status = 'posted') = (ledger_transaction_id IS NOT NULL))");
        DB::statement('ALTER TABLE ledger_adjustments ADD CONSTRAINT ledger_adjustments_kind_check CHECK ((lines IS NULL) <> (reverses_transaction_id IS NULL))');
        // One reversal per posting, whoever asks.
        DB::statement("CREATE UNIQUE INDEX ledger_adjustments_one_reversal ON ledger_adjustments (reverses_transaction_id) WHERE reverses_transaction_id IS NOT NULL AND status <> 'rejected'");
        DB::statement('CREATE UNIQUE INDEX ledger_transactions_one_reversal ON ledger_transactions (reverses_transaction_id) WHERE reverses_transaction_id IS NOT NULL');

        // A payout from Moyasar to the bank, as Moyasar reports it. Written
        // once, with its ledger posting, and never changed.
        Schema::create('moyasar_settlements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('provider_settlement_id', 100)->unique();
            $table->char('currency', 3)->default('SAR');
            // Before fees: what left Moyasar clearing.
            $table->bigInteger('gross_halalas');
            // Moyasar's fees, VAT included, and that VAT.
            $table->bigInteger('fee_halalas');
            $table->bigInteger('fee_vat_halalas');
            // What reached the bank.
            $table->bigInteger('net_halalas');
            $table->timestampTz('settled_at')->nullable();
            $table->unsignedInteger('line_count');
            // Lines for a payment we have no record of.
            $table->unsignedInteger('unmatched_count');
            $table->jsonb('raw');
            $table->foreignId('ledger_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['settled_at', 'id']);
        });

        DB::statement("ALTER TABLE moyasar_settlements ADD CONSTRAINT moyasar_settlements_currency_check CHECK (currency = 'SAR')");
        DB::statement('ALTER TABLE moyasar_settlements ADD CONSTRAINT moyasar_settlements_sums_check CHECK (gross_halalas = net_halalas + fee_halalas AND unmatched_count <= line_count)');
        // Nothing to post only when every amount is zero.
        DB::statement('ALTER TABLE moyasar_settlements ADD CONSTRAINT moyasar_settlements_posting_check CHECK ((ledger_transaction_id IS NULL) = (gross_halalas = 0 AND fee_halalas = 0 AND net_halalas = 0))');

        Schema::create('moyasar_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('moyasar_settlement_id')->constrained()->restrictOnDelete();
            $table->string('type', 32);
            $table->string('provider_payment_id', 100)->nullable();
            $table->foreignId('payment_authorization_id')->nullable()->constrained()->restrictOnDelete();
            // Net of the fee; negative for refunds and charges.
            $table->bigInteger('amount_halalas');
            $table->bigInteger('fee_halalas');
            $table->bigInteger('fee_vat_halalas');
            $table->bigInteger('payment_amount_halalas')->nullable();
            $table->timestampTz('transacted_at')->nullable();
            $table->jsonb('raw');

            $table->index(['moyasar_settlement_id', 'id']);
            $table->index('payment_authorization_id');
            $table->index('provider_payment_id');
        });

        AppendOnly::protect('moyasar_settlements');
        AppendOnly::protect('moyasar_settlement_lines');
    }

    public function down(): void
    {
        AppendOnly::release('moyasar_settlement_lines');
        AppendOnly::release('moyasar_settlements');
        Schema::dropIfExists('moyasar_settlement_lines');
        Schema::dropIfExists('moyasar_settlements');
        DB::statement('DROP INDEX IF EXISTS ledger_transactions_one_reversal');
        Schema::dropIfExists('ledger_adjustments');

        // Postings of the new kinds, and the new account, cannot simply go:
        // ledger rows are append-only. down() works only before any exist.
        DB::statement('ALTER TABLE ledger_transactions DROP CONSTRAINT ledger_transactions_type_check');
        DB::statement("ALTER TABLE ledger_transactions ADD CONSTRAINT ledger_transactions_type_check CHECK (type IN ('capture', 'refund', 'revenue_recognition', 'adjustment', 'reversal'))");
        DB::table('ledger_accounts')->where('code', 'vat_input_recoverable')->delete();
    }
};
