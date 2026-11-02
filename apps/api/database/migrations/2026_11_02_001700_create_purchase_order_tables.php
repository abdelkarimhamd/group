<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The one bulk Alibaba order per successful campaign, created by the
        // closing job for the captured units. Its state is its own: customer
        // orders and payments keep theirs.
        Schema::create('supplier_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('po_number', 24)->unique();
            $table->foreignId('campaign_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_quotation_id')->constrained()->restrictOnDelete();
            $table->string('alibaba_order_ref', 100)->nullable();
            // In the supplier's currency; the SAR value is for the ledger.
            $table->bigInteger('total_minor');
            $table->char('currency', 3);
            $table->decimal('fx_rate_to_sar', 18, 8)->nullable();
            $table->bigInteger('total_halalas')->nullable();
            $table->string('status', 24)->default('draft');
            $table->timestampTz('placed_at')->nullable();
            $table->timestampTz('supplier_confirmed_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('freight_forwarder', 100)->nullable();
            $table->string('freight_tracking_ref', 100)->nullable();
            $table->date('eta')->nullable();
            $table->foreignId('placed_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'id']);
        });

        // PO-2611-0007. Owned by the column, so the sequence goes with the table.
        DB::statement('CREATE SEQUENCE purchase_order_number_seq OWNED BY supplier_purchase_orders.po_number');
        DB::statement("ALTER TABLE supplier_purchase_orders ADD CONSTRAINT supplier_purchase_orders_status_check CHECK (status IN ('draft', 'placed', 'supplier_confirmed', 'paid', 'shipped', 'received', 'cancelled'))");
        DB::statement("ALTER TABLE supplier_purchase_orders ADD CONSTRAINT supplier_purchase_orders_currency_check CHECK (currency IN ('SAR', 'USD', 'CNY'))");
        DB::statement('ALTER TABLE supplier_purchase_orders ADD CONSTRAINT supplier_purchase_orders_amounts_check CHECK (total_minor >= 0 AND (fx_rate_to_sar IS NULL OR fx_rate_to_sar > 0) AND (total_halalas IS NULL) = (fx_rate_to_sar IS NULL) AND (total_halalas IS NULL OR total_halalas >= 0))');
        DB::statement("ALTER TABLE supplier_purchase_orders ADD CONSTRAINT supplier_purchase_orders_sar_rate_check CHECK (currency <> 'SAR' OR fx_rate_to_sar = 1)");
        // Past draft, the order exists at Alibaba and its SAR value is known.
        DB::statement("ALTER TABLE supplier_purchase_orders ADD CONSTRAINT supplier_purchase_orders_placed_check CHECK (status IN ('draft', 'cancelled') OR (alibaba_order_ref IS NOT NULL AND total_halalas IS NOT NULL AND placed_at IS NOT NULL))");
        DB::statement("ALTER TABLE supplier_purchase_orders ADD CONSTRAINT supplier_purchase_orders_shipped_check CHECK (status NOT IN ('shipped', 'received') OR (freight_tracking_ref IS NOT NULL AND shipped_at IS NOT NULL))");

        // What was bought: the captured units of each variant.
        Schema::create('supplier_purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->bigInteger('unit_cost_minor');
            $table->timestampsTz();

            $table->unique(['supplier_purchase_order_id', 'product_variant_id']);
        });

        DB::statement('ALTER TABLE supplier_purchase_order_lines ADD CONSTRAINT supplier_purchase_order_lines_amounts_check CHECK (qty > 0 AND unit_cost_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_purchase_order_lines');
        Schema::dropIfExists('supplier_purchase_orders');
    }
};
