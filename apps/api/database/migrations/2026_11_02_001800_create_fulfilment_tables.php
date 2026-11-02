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
        // Goods counted at the pilot warehouse: one receipt per supplier
        // order. No stock or bin tables.
        Schema::create('inbound_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('supplier_purchase_order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('warehouse_name', 100);
            $table->timestampTz('received_at');
            $table->foreignId('received_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->jsonb('photo_s3_keys')->default('[]');
            $table->timestampsTz();
        });

        DB::statement("ALTER TABLE inbound_receipts ADD CONSTRAINT inbound_receipts_photos_check CHECK (jsonb_typeof(photo_s3_keys) = 'array')");

        Schema::create('inbound_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inbound_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty_expected');
            $table->unsignedInteger('qty_received');
            $table->unsignedInteger('qty_damaged');
            $table->timestampsTz();

            $table->unique(['inbound_receipt_id', 'product_variant_id']);
        });

        DB::statement('ALTER TABLE inbound_receipt_lines ADD CONSTRAINT inbound_receipt_lines_qty_check CHECK (qty_expected >= 0 AND qty_received >= 0 AND qty_damaged >= 0 AND qty_damaged <= qty_received)');

        // The last mile: one shipment per customer order in the pilot.
        // Direct shipping from the supplier exists in the model but is off.
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('type', 24)->default('warehouse_to_customer');
            $table->string('courier', 100);
            $table->string('tracking_number', 100);
            $table->string('tracking_url', 2048)->nullable();
            $table->string('status', 20)->default('picked_up');
            $table->bigInteger('shipping_cost_halalas')->nullable();
            $table->timestampTz('shipped_at');
            $table->timestampTz('delivered_at')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'id']);
        });

        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_type_check CHECK (type IN ('warehouse_to_customer', 'direct_from_supplier'))");
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_status_check CHECK (status IN ('pending', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed_attempt', 'returned', 'cancelled'))");
        DB::statement('ALTER TABLE shipments ADD CONSTRAINT shipments_cost_check CHECK (shipping_cost_halalas IS NULL OR shipping_cost_halalas >= 0)');
        DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_delivered_check CHECK ((status = 'delivered') = (delivered_at IS NOT NULL))");

        // Tracking history, oldest first; never changed.
        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->string('description', 500)->nullable();
            $table->string('location', 200)->nullable();
            $table->string('source', 16);
            $table->jsonb('raw_payload')->nullable();
            $table->timestampTz('occurred_at');
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->timestampTz('created_at');

            $table->index(['shipment_id', 'occurred_at', 'id']);
        });

        DB::statement("ALTER TABLE shipment_events ADD CONSTRAINT shipment_events_status_check CHECK (status IN ('pending', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed_attempt', 'returned', 'cancelled'))");
        DB::statement("ALTER TABLE shipment_events ADD CONSTRAINT shipment_events_source_check CHECK (source IN ('courier_api', 'admin'))");
        DB::statement("ALTER TABLE shipment_events ADD CONSTRAINT shipment_events_actor_check CHECK (actor_type IN ('system', 'admin', 'user'))");
        AppendOnly::protect('shipment_events');
    }

    public function down(): void
    {
        AppendOnly::release('shipment_events');
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('inbound_receipt_lines');
        Schema::dropIfExists('inbound_receipts');
    }
};
