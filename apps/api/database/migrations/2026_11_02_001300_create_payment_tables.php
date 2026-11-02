<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per Moyasar payment attempt, which is one hold. A declined
        // attempt may be followed by a new row for the same participation.
        Schema::create('payment_authorizations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('participation_id')->constrained('campaign_participations')->restrictOnDelete();
            $table->string('provider', 16)->default('moyasar');
            $table->string('provider_payment_id', 100)->nullable()->unique();
            $table->string('source_type', 16)->nullable();
            $table->string('scheme', 16)->nullable();
            $table->char('card_last4', 4)->nullable();
            $table->bigInteger('authorized_amount_halalas');
            $table->bigInteger('captured_amount_halalas')->default(0);
            $table->bigInteger('refunded_amount_halalas')->default(0);
            $table->char('currency', 3)->default('SAR');
            $table->string('status', 24)->default('pending_authorization');
            $table->string('rejection_reason', 32)->nullable();
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->timestampTz('expires_at');
            $table->string('provider_status', 32)->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->index(['participation_id', 'id']);
            $table->index(['status', 'id']);
        });

        DB::statement("ALTER TABLE payment_authorizations ADD CONSTRAINT payment_authorizations_status_check CHECK (status IN ('pending_authorization', 'authorized', 'failed', 'voided', 'expired', 'captured', 'capture_failed', 'partially_refunded', 'refunded'))");
        DB::statement("ALTER TABLE payment_authorizations ADD CONSTRAINT payment_authorizations_rejection_reason_check CHECK (rejection_reason IN ('declined', 'amount_mismatch', 'currency_mismatch', 'method_not_allowed', 'not_authorize_only', 'reservation_expired', 'campaign_closed', 'participation_cancelled'))");
        DB::statement("ALTER TABLE payment_authorizations ADD CONSTRAINT payment_authorizations_source_type_check CHECK (source_type IN ('creditcard', 'applepay'))");
        DB::statement("ALTER TABLE payment_authorizations ADD CONSTRAINT payment_authorizations_scheme_check CHECK (scheme IN ('mada', 'visa', 'mastercard'))");
        DB::statement("ALTER TABLE payment_authorizations ADD CONSTRAINT payment_authorizations_currency_check CHECK (currency = 'SAR')");
        DB::statement('ALTER TABLE payment_authorizations ADD CONSTRAINT payment_authorizations_amounts_check CHECK (authorized_amount_halalas > 0 AND captured_amount_halalas >= 0 AND captured_amount_halalas <= authorized_amount_halalas AND refunded_amount_halalas >= 0 AND refunded_amount_halalas <= captured_amount_halalas)');
        // Never two live holds for one join.
        DB::statement("CREATE UNIQUE INDEX payment_authorizations_one_live_hold ON payment_authorizations (participation_id) WHERE status IN ('authorized', 'captured')");
        // Expiry alerts and the extension check read authorized holds by expiry.
        DB::statement("CREATE INDEX payment_authorizations_authorized_expiry ON payment_authorizations (expires_at) WHERE status = 'authorized'");

        // One row per call to Moyasar, written before the call is sent. The
        // idempotency key makes a retry reuse its row, so each hold gets at
        // most one capture and one void.
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_authorization_id')->constrained()->restrictOnDelete();
            $table->string('type', 16);
            $table->bigInteger('amount_halalas');
            $table->char('currency', 3)->default('SAR');
            $table->string('idempotency_key', 100)->unique();
            $table->string('status', 16)->default('pending');
            $table->string('provider_reference', 100)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->string('requested_by_type', 16);
            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->index(['payment_authorization_id', 'id']);
        });

        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_type_check CHECK (type IN ('authorize', 'capture', 'void', 'refund'))");
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_status_check CHECK (status IN ('pending', 'succeeded', 'failed'))");
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_requested_by_check CHECK (requested_by_type IN ('system', 'admin', 'user'))");
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_currency_check CHECK (currency = 'SAR')");
        DB::statement('ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_amount_check CHECK (amount_halalas >= 0)');
        DB::statement("ALTER TABLE payment_transactions ADD CONSTRAINT payment_transactions_completed_check CHECK ((status = 'pending') = (completed_at IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payment_authorizations');
    }
};
