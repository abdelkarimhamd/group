<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Actions that move money after the fact wait here for a second admin
        // with the finance role. The approve and reject endpoints come with
        // the finance epic (E15); closing (E10) opens campaign cancellations.
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('type', 32);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->jsonb('payload');
            $table->foreignId('requested_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->text('reason')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'id']);
            $table->index(['subject_type', 'subject_id']);
        });

        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_type_check CHECK (type IN ('manual_refund', 'manual_capture', 'manual_void', 'ledger_adjustment', 'campaign_cancellation'))");
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_status_check CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))");
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_payload_check CHECK (jsonb_typeof(payload) = 'object')");
        // The person who asks never approves their own request.
        DB::statement('ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_second_admin_check CHECK (decided_by_admin_id IS NULL OR decided_by_admin_id <> requested_by_admin_id)');
        DB::statement("ALTER TABLE approval_requests ADD CONSTRAINT approval_requests_decision_check CHECK ((status IN ('approved', 'rejected')) = (decided_at IS NOT NULL))");
        // Asking twice for the same thing finds the request already waiting.
        DB::statement("CREATE UNIQUE INDEX approval_requests_one_pending ON approval_requests (type, subject_type, subject_id) WHERE status = 'pending'");

        // A capture that was declined, or that could not be made before the
        // hold's capture cutoff. Both leave the hold to be voided.
        DB::statement('ALTER TABLE payment_authorizations DROP CONSTRAINT payment_authorizations_rejection_reason_check');
        DB::statement("ALTER TABLE payment_authorizations ADD CONSTRAINT payment_authorizations_rejection_reason_check CHECK (rejection_reason IN ('declined', 'amount_mismatch', 'currency_mismatch', 'method_not_allowed', 'not_authorize_only', 'reservation_expired', 'campaign_closed', 'participation_cancelled', 'duplicate_hold', 'capture_declined', 'capture_cutoff_passed'))");

        // The hourly expiry job reads holds that are still open by expiry.
        DB::statement("CREATE INDEX payment_authorizations_open_expiry ON payment_authorizations (expires_at) WHERE status IN ('authorized', 'capture_failed')");

        // The closing sweep reads campaigns that are still closing.
        DB::statement("CREATE INDEX campaigns_closing ON campaigns (id) WHERE status = 'closing' OR closing_step = 'notifying'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX campaigns_closing');
        DB::statement('DROP INDEX payment_authorizations_open_expiry');
        DB::statement('ALTER TABLE payment_authorizations DROP CONSTRAINT payment_authorizations_rejection_reason_check');
        DB::statement("ALTER TABLE payment_authorizations ADD CONSTRAINT payment_authorizations_rejection_reason_check CHECK (rejection_reason IN ('declined', 'amount_mismatch', 'currency_mismatch', 'method_not_allowed', 'not_authorize_only', 'reservation_expired', 'campaign_closed', 'participation_cancelled', 'duplicate_hold'))");
        Schema::dropIfExists('approval_requests');
    }
};
