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
        // One group buy. committed_qty and reserved_qty change only through
        // conditional UPDATEs in App\Modules\Campaign\Services\Joins, never
        // through a model save; the CHECK below is the last defence against
        // overselling.
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('code', 20)->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_quotation_id')->constrained()->restrictOnDelete();
            $table->string('title_ar', 200);
            $table->string('title_en', 200);
            $table->unsignedInteger('min_target_qty');
            $table->unsignedInteger('max_qty');
            $table->unsignedInteger('committed_qty')->default(0);
            $table->unsignedInteger('reserved_qty')->default(0);
            $table->unsignedInteger('per_customer_max_qty')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('deadline_at')->nullable();
            $table->timestampTz('original_deadline_at')->nullable();
            $table->timestampTz('extended_at')->nullable();
            $table->foreignId('extended_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->text('extension_reason')->nullable();
            $table->string('status', 20)->default('draft');
            $table->timestampTz('deal_confirmed_at')->nullable();
            $table->string('close_reason', 20)->nullable();
            $table->string('closing_step', 20)->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->unsignedInteger('final_qty')->nullable();
            $table->unsignedBigInteger('final_tier_id')->nullable();
            $table->bigInteger('final_unit_price_halalas')->nullable();
            $table->timestampTz('capture_by')->nullable();
            $table->unsignedInteger('captured_qty')->nullable();
            $table->text('end_reason')->nullable();
            $table->unsignedInteger('current_tier_version')->nullable();
            $table->bigInteger('landed_cost_per_unit_halalas')->nullable();
            $table->char('currency', 3)->default('SAR');
            $table->string('delivery_mode', 16)->default('warehouse');
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('approved_by_admin_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->text('margin_override_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestampsTz();

            $table->index(['status', 'deadline_at']);
            $table->index(['status', 'starts_at']);
            $table->index(['product_id', 'id']);
            $table->index('supplier_quotation_id');
        });

        // Human campaign references such as GB-2611-0007 take their number
        // from here. Owned by the column, so it goes when the table does.
        DB::statement('CREATE SEQUENCE campaign_code_seq OWNED BY campaigns.code');

        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_status_check CHECK (status IN ('draft', 'pending_approval', 'scheduled', 'active', 'closing', 'purchasing', 'fulfilling', 'completed', 'failed', 'cancelled'))");
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_close_reason_check CHECK (close_reason IN ('deadline', 'max_reached'))");
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_closing_step_check CHECK (closing_step IN ('settling_joins', 'awaiting_supplier', 'capturing', 'needs_admin', 'notifying', 'done'))");
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_delivery_mode_check CHECK (delivery_mode IN ('warehouse', 'direct'))");
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_currency_check CHECK (currency = 'SAR')");
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_quantities_check CHECK (min_target_qty > 0 AND max_qty >= min_target_qty AND (per_customer_max_qty IS NULL OR per_customer_max_qty > 0))');
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_counters_check CHECK (committed_qty >= 0 AND reserved_qty >= 0 AND committed_qty + reserved_qty <= max_qty)');
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_deadline_check CHECK (deadline_at IS NOT NULL OR status IN ('draft', 'pending_approval', 'cancelled'))");
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_extension_check CHECK ((extended_at IS NULL) = (original_deadline_at IS NULL) AND (extended_at IS NULL) = (extended_by_admin_id IS NULL))');
        DB::statement("ALTER TABLE campaigns ADD CONSTRAINT campaigns_end_reason_check CHECK (status NOT IN ('failed', 'cancelled') OR end_reason IS NOT NULL)");

        // All variants of one campaign count toward one shared target and maximum.
        Schema::create('campaign_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['campaign_id', 'product_variant_id']);
        });

        // Versioned price tiers. Each save inserts a full new set; rows are
        // never changed, so every price a customer was shown can be traced.
        Schema::create('campaign_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedInteger('min_qty');
            $table->unsignedInteger('max_qty');
            $table->bigInteger('unit_price_halalas');
            $table->char('currency', 3)->default('SAR');
            $table->foreignId('created_by_admin_id')->constrained('admin_users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['campaign_id', 'version', 'min_qty']);
        });

        DB::statement('ALTER TABLE campaign_price_tiers ADD CONSTRAINT campaign_price_tiers_range_check CHECK (version > 0 AND min_qty > 0 AND max_qty >= min_qty AND unit_price_halalas > 0)');
        DB::statement("ALTER TABLE campaign_price_tiers ADD CONSTRAINT campaign_price_tiers_currency_check CHECK (currency = 'SAR')");
        AppendOnly::protect('campaign_price_tiers');
        DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_final_tier_id_foreign FOREIGN KEY (final_tier_id) REFERENCES campaign_price_tiers (id)');

        // One customer's join: the unit that is held, captured and turned into an order.
        Schema::create('campaign_participations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->bigInteger('hold_unit_price_halalas');
            $table->bigInteger('hold_amount_halalas');
            $table->unsignedInteger('tier_version');
            $table->bigInteger('final_unit_price_halalas')->nullable();
            $table->bigInteger('capture_amount_halalas')->nullable();
            $table->char('currency', 3)->default('SAR');
            // The live address, so a fix the customer makes before close is
            // used; the snapshot covers an address deleted meanwhile.
            $table->foreignId('delivery_address_id')->nullable()->constrained('addresses')->nullOnDelete();
            $table->jsonb('delivery_address');
            $table->string('status', 24)->default('pending_payment');
            $table->timestampTz('reservation_expires_at');
            $table->uuid('client_request_id');
            $table->timestampTz('authorized_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('captured_at')->nullable();
            $table->string('cancel_reason', 40)->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'client_request_id']);
            $table->index(['campaign_id', 'status']);
            $table->index(['campaign_id', 'user_id']);
            $table->index(['status', 'reservation_expires_at']);
        });

        DB::statement("ALTER TABLE campaign_participations ADD CONSTRAINT campaign_participations_status_check CHECK (status IN ('pending_payment', 'authorized', 'payment_failed', 'reservation_expired', 'cancelled', 'released', 'captured', 'capture_failed'))");
        DB::statement('ALTER TABLE campaign_participations ADD CONSTRAINT campaign_participations_amounts_check CHECK (qty > 0 AND hold_unit_price_halalas > 0 AND hold_amount_halalas >= qty * hold_unit_price_halalas AND tier_version > 0)');
        DB::statement('ALTER TABLE campaign_participations ADD CONSTRAINT campaign_participations_capture_check CHECK (capture_amount_halalas IS NULL OR (capture_amount_halalas > 0 AND capture_amount_halalas <= hold_amount_halalas))');
        DB::statement("ALTER TABLE campaign_participations ADD CONSTRAINT campaign_participations_currency_check CHECK (currency = 'SAR')");
        DB::statement("ALTER TABLE campaign_participations ADD CONSTRAINT campaign_participations_address_check CHECK (jsonb_typeof(delivery_address) = 'object')");

        // Append-only campaign history: every status change, price change and milestone.
        Schema::create('campaign_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('campaign_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->unsignedInteger('committed_qty_after');
            $table->jsonb('payload');
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['campaign_id', 'id']);
        });

        DB::statement("ALTER TABLE campaign_events ADD CONSTRAINT campaign_events_actor_type_check CHECK (actor_type IN ('system', 'admin', 'user'))");
        DB::statement("ALTER TABLE campaign_events ADD CONSTRAINT campaign_events_payload_check CHECK (jsonb_typeof(payload) = 'object')");
        AppendOnly::protect('campaign_events');
    }

    public function down(): void
    {
        AppendOnly::release('campaign_events');
        Schema::dropIfExists('campaign_events');
        Schema::dropIfExists('campaign_participations');
        DB::statement('ALTER TABLE campaigns DROP CONSTRAINT campaigns_final_tier_id_foreign');
        AppendOnly::release('campaign_price_tiers');
        Schema::dropIfExists('campaign_price_tiers');
        Schema::dropIfExists('campaign_variants');
        Schema::dropIfExists('campaigns');
    }
};
