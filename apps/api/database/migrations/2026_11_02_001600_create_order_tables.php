<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One order per captured join, created in the transaction that
        // records the capture. The amounts are VAT-inclusive and the total
        // is what was captured; payment state is read from the payment.
        Schema::create('customer_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('order_number', 24)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('participation_id')->unique()->constrained('campaign_participations')->restrictOnDelete();
            $table->foreignId('payment_authorization_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->bigInteger('unit_price_halalas');
            $table->bigInteger('goods_total_halalas');
            $table->bigInteger('delivery_fee_halalas');
            $table->bigInteger('total_halalas');
            $table->bigInteger('vat_halalas');
            $table->unsignedSmallInteger('vat_rate_bp');
            $table->char('currency', 3)->default('SAR');
            $table->jsonb('shipping_address');
            $table->string('status', 20)->default('confirmed');
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('return_window_ends_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'id']);
            $table->index(['campaign_id', 'id']);
            $table->index(['status', 'id']);
        });

        // Human references, never reused: ORD-2611-000123. Owned by the column,
        // so the sequence goes when the table does.
        DB::statement('CREATE SEQUENCE customer_order_number_seq OWNED BY customer_orders.order_number');
        DB::statement("ALTER TABLE customer_orders ADD CONSTRAINT customer_orders_status_check CHECK (status IN ('confirmed', 'processing', 'ready_to_ship', 'shipped', 'delivered', 'completed', 'cancelled', 'return_requested', 'returned'))");
        DB::statement("ALTER TABLE customer_orders ADD CONSTRAINT customer_orders_currency_check CHECK (currency = 'SAR')");
        DB::statement('ALTER TABLE customer_orders ADD CONSTRAINT customer_orders_amounts_check CHECK (qty > 0 AND unit_price_halalas > 0 AND goods_total_halalas = unit_price_halalas * qty AND delivery_fee_halalas >= 0 AND total_halalas = goods_total_halalas + delivery_fee_halalas AND vat_halalas >= 0 AND vat_halalas < total_halalas)');
        DB::statement("ALTER TABLE customer_orders ADD CONSTRAINT customer_orders_address_check CHECK (jsonb_typeof(shipping_address) = 'object')");
        DB::statement('ALTER TABLE customer_orders ADD CONSTRAINT customer_orders_return_window_check CHECK ((delivered_at IS NULL) = (return_window_ends_at IS NULL))');

        // VAT invoices and credit notes: the company is the seller of record.
        // Amounts are written once; only the ZATCA and file columns change.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_order_id')->constrained()->restrictOnDelete();
            $table->string('type', 24);
            $table->string('invoice_number', 24)->unique();
            $table->foreignId('original_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->timestampTz('issued_at');
            $table->jsonb('seller_snapshot');
            $table->jsonb('lines');
            $table->bigInteger('net_halalas');
            $table->bigInteger('vat_halalas');
            $table->bigInteger('total_halalas');
            $table->unsignedSmallInteger('vat_rate_bp');
            $table->char('currency', 3)->default('SAR');
            $table->uuid('zatca_uuid')->unique();
            $table->string('zatca_status', 16)->default('pending');
            $table->text('qr_payload');
            $table->string('xml_s3_key')->nullable();
            $table->string('pdf_s3_key')->nullable();
            $table->timestampsTz();

            $table->index(['customer_order_id', 'id']);
        });

        // INV-2026-000123 and CRN-2026-000124: one counter for invoices and credit notes.
        DB::statement('CREATE SEQUENCE invoice_number_seq OWNED BY invoices.invoice_number');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_type_check CHECK (type IN ('simplified_tax_invoice', 'credit_note'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_zatca_status_check CHECK (zatca_status IN ('pending', 'submitted', 'accepted', 'rejected'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_currency_check CHECK (currency = 'SAR')");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_amounts_check CHECK (net_halalas >= 0 AND vat_halalas >= 0 AND net_halalas + vat_halalas = total_halalas AND total_halalas > 0)');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_credit_note_check CHECK ((type = 'credit_note') = (original_invoice_id IS NOT NULL))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_json_check CHECK (jsonb_typeof(seller_snapshot) = 'object' AND jsonb_typeof(lines) = 'array')");
        // One tax invoice per order; credit notes may follow.
        DB::statement("CREATE UNIQUE INDEX invoices_one_tax_invoice ON invoices (customer_order_id) WHERE type = 'simplified_tax_invoice'");
        // An issued invoice never changes its number, parties, lines or amounts.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION invoices_keep_issued() RETURNS trigger AS $$
            BEGIN
                IF (NEW.customer_order_id, NEW.type, NEW.invoice_number, NEW.original_invoice_id, NEW.issued_at, NEW.seller_snapshot, NEW.lines,
                    NEW.net_halalas, NEW.vat_halalas, NEW.total_halalas, NEW.vat_rate_bp, NEW.currency, NEW.zatca_uuid, NEW.qr_payload)
                   IS DISTINCT FROM
                   (OLD.customer_order_id, OLD.type, OLD.invoice_number, OLD.original_invoice_id, OLD.issued_at, OLD.seller_snapshot, OLD.lines,
                    OLD.net_halalas, OLD.vat_halalas, OLD.total_halalas, OLD.vat_rate_bp, OLD.currency, OLD.zatca_uuid, OLD.qr_payload) THEN
                    RAISE EXCEPTION 'An issued invoice cannot change; issue a credit note instead.' USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::statement('CREATE TRIGGER invoices_keep_issued BEFORE UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION invoices_keep_issued()');
        DB::statement('CREATE TRIGGER invoices_no_delete BEFORE DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation()');

        // Money returned after a capture. Each starts waiting for a second
        // admin with the finance role; the refund is sent once approved.
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_authorization_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->bigInteger('amount_halalas');
            $table->char('currency', 3)->default('SAR');
            $table->string('method', 8);
            $table->string('reason', 40);
            $table->string('status', 20)->default('pending_approval');
            $table->text('note')->nullable();
            $table->string('provider_refund_id', 100)->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->string('requested_by_type', 16);
            $table->unsignedBigInteger('requested_by_id')->nullable();
            $table->foreignId('approval_request_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('credit_note_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->index(['payment_authorization_id', 'id']);
            $table->index(['status', 'id']);
        });

        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_method_check CHECK (method IN ('void', 'refund'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_reason_check CHECK (reason IN ('customer_cancellation', 'delivery_delay', 'defective', 'shortage', 'campaign_cancelled_after_capture', 'other'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ('pending_approval', 'approved', 'processing', 'succeeded', 'failed', 'rejected'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_currency_check CHECK (currency = 'SAR')");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check CHECK (amount_halalas > 0)');
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_requested_by_check CHECK (requested_by_type IN ('system', 'admin', 'user'))");
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_processed_check CHECK ((status IN ('succeeded', 'failed')) = (processed_at IS NOT NULL))");

    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        DB::statement('DROP TRIGGER IF EXISTS invoices_no_delete ON invoices');
        DB::statement('DROP TRIGGER IF EXISTS invoices_keep_issued ON invoices');
        Schema::dropIfExists('invoices');
        DB::statement('DROP FUNCTION IF EXISTS invoices_keep_issued()');
        Schema::dropIfExists('customer_orders');
    }
};
