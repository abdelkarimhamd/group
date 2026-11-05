<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Courier bookings, tracking pulled from the courier, and customer
     * returns (E13).
     *
     * - A shipment can be booked through the courier's API: it starts
     *   `pending`, without a tracking number until the courier answers and
     *   without `shipped_at` until the courier picks it up. The row is the
     *   booking's attempt record: `booking_key` goes to the courier as our
     *   reference.
     * - A parcel the courier brings back (`returned`) or a booking cancelled
     *   before pickup frees the order for a new shipment, so "one shipment
     *   per order" becomes "one open shipment per order".
     * - Tracking pulled from the courier carries the courier's own key, so
     *   reading the same update twice records it once.
     * - A customer return is recorded by staff within the return window and
     *   approved (a refund request) or rejected.
     */
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropUnique(['customer_order_id']);
            $table->string('tracking_number', 100)->nullable()->change();
            $table->timestampTz('shipped_at')->nullable()->change();
            $table->string('booking_status', 16)->nullable();
            $table->uuid('booking_key')->nullable()->unique();
            $table->timestampTz('booking_requested_at')->nullable();
            // Set just before the call goes out: a booking with it set is
            // never sent again on its own.
            $table->timestampTz('booking_sent_at')->nullable();
            $table->string('booking_error', 500)->nullable();
            $table->string('label_path', 500)->nullable();
            $table->timestampTz('last_tracked_at')->nullable();
            $table->index('customer_order_id');
        });
        DB::statement("CREATE UNIQUE INDEX shipments_one_open_per_order ON shipments (customer_order_id) WHERE status NOT IN ('returned', 'cancelled')");
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_booking_status_check CHECK (booking_status IS NULL OR booking_status IN ('requested', 'booked', 'failed'))");
        DB::statement('ALTER TABLE shipments ADD CONSTRAINT shipments_booking_key_check CHECK ((booking_status IS NULL) = (booking_key IS NULL))');
        // Only a booking not yet picked up (or cancelled before it was) has no
        // tracking number or pickup time.
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_picked_up_check CHECK (status IN ('pending', 'cancelled') OR (tracking_number IS NOT NULL AND shipped_at IS NOT NULL))");

        Schema::table('shipment_events', function (Blueprint $table) {
            $table->string('external_key', 64)->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX shipment_events_external_key_unique ON shipment_events (shipment_id, external_key) WHERE external_key IS NOT NULL');

        Schema::create('order_returns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('requested');
            $table->string('reason', 32);
            $table->text('note')->nullable();
            $table->foreignId('recorded_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('decided_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('refund_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'id']);
        });
        DB::statement("ALTER TABLE order_returns ADD CONSTRAINT order_returns_status_check CHECK (status IN ('requested', 'approved', 'rejected', 'completed'))");
        DB::statement("ALTER TABLE order_returns ADD CONSTRAINT order_returns_reason_check CHECK (reason IN ('defective', 'damaged_in_transit', 'wrong_item', 'not_as_described', 'changed_mind', 'other'))");
        DB::statement("ALTER TABLE order_returns ADD CONSTRAINT order_returns_decided_check CHECK ((status = 'requested') = (decided_at IS NULL))");

        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_reason_check');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_reason_check CHECK (reason IN ('customer_cancellation', 'delivery_delay', 'defective', 'shortage', 'campaign_cancelled_after_capture', 'customer_return', 'other'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_reason_check');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_reason_check CHECK (reason IN ('customer_cancellation', 'delivery_delay', 'defective', 'shortage', 'campaign_cancelled_after_capture', 'other'))");

        Schema::dropIfExists('order_returns');

        DB::statement('DROP INDEX shipment_events_external_key_unique');
        Schema::table('shipment_events', function (Blueprint $table) {
            $table->dropColumn('external_key');
        });

        DB::statement('ALTER TABLE shipments DROP CONSTRAINT shipments_picked_up_check');
        DB::statement('ALTER TABLE shipments DROP CONSTRAINT shipments_booking_key_check');
        DB::statement('ALTER TABLE shipments DROP CONSTRAINT shipments_booking_status_check');
        DB::statement('DROP INDEX shipments_one_open_per_order');
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropIndex(['customer_order_id']);
            $table->dropColumn(['booking_status', 'booking_key', 'booking_requested_at', 'booking_sent_at', 'booking_error', 'label_path', 'last_tracked_at']);
            $table->timestampTz('shipped_at')->nullable(false)->change();
            $table->string('tracking_number', 100)->nullable(false)->change();
            $table->unique('customer_order_id');
        });
    }
};
