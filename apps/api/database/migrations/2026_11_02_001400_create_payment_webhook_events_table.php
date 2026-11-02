<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every webhook as received. A row that fails verification is kept
        // but never processed; the payload is never edited.
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16)->default('moyasar');
            $table->string('provider_event_id', 100);
            $table->string('event_type', 64)->nullable();
            $table->string('provider_payment_id', 100)->nullable();
            $table->boolean('signature_valid');
            $table->jsonb('payload');
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('provider_payment_id');
        });

        // A verified event is stored once; Moyasar's resends are ignored. Rows
        // that failed verification do not count, so a forged event cannot
        // take a real event's ID first.
        DB::statement('CREATE UNIQUE INDEX payment_webhook_events_verified_once ON payment_webhook_events (provider, provider_event_id) WHERE signature_valid');

        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT payment_webhook_events_payload_check CHECK (jsonb_typeof(payload) = 'object')");

        // A hold that is accepted counts as the join's live hold. One being
        // voided (it carries a rejection reason) does not, so a second hold
        // found for the same join can be recorded and voided too.
        DB::statement('DROP INDEX payment_authorizations_one_live_hold');
        DB::statement("CREATE UNIQUE INDEX payment_authorizations_one_live_hold ON payment_authorizations (participation_id) WHERE status IN ('authorized', 'captured') AND rejection_reason IS NULL");

        // A second hold for a join that already has one.
        DB::statement('ALTER TABLE payment_authorizations DROP CONSTRAINT payment_authorizations_rejection_reason_check');
        DB::statement("ALTER TABLE payment_authorizations ADD CONSTRAINT payment_authorizations_rejection_reason_check CHECK (rejection_reason IN ('declined', 'amount_mismatch', 'currency_mismatch', 'method_not_allowed', 'not_authorize_only', 'reservation_expired', 'campaign_closed', 'participation_cancelled', 'duplicate_hold'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payment_authorizations DROP CONSTRAINT payment_authorizations_rejection_reason_check');
        DB::statement("ALTER TABLE payment_authorizations ADD CONSTRAINT payment_authorizations_rejection_reason_check CHECK (rejection_reason IN ('declined', 'amount_mismatch', 'currency_mismatch', 'method_not_allowed', 'not_authorize_only', 'reservation_expired', 'campaign_closed', 'participation_cancelled'))");
        DB::statement('DROP INDEX payment_authorizations_one_live_hold');
        DB::statement("CREATE UNIQUE INDEX payment_authorizations_one_live_hold ON payment_authorizations (participation_id) WHERE status IN ('authorized', 'captured')");
        Schema::dropIfExists('payment_webhook_events');
    }
};
