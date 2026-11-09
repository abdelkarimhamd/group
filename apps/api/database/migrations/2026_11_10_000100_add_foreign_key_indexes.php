<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Indexes for foreign keys that are read or followed on busy paths.
 * PostgreSQL does not index a foreign key on its own, so without these:
 *
 * - an order's refunds (order pages, refund checks) scan every refund;
 * - signing out deletes the token's push devices (ON DELETE CASCADE) by
 *   scanning every device token;
 * - deleting an address clears it from joins (ON DELETE SET NULL) by
 *   scanning every join. Most joins keep their address, so the index
 *   leaves out the ones without.
 *
 * Foreign keys to admin users are left unindexed: admins are blocked,
 * never deleted, and those columns are only shown, not searched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX refunds_customer_order_id_index ON refunds (customer_order_id) WHERE customer_order_id IS NOT NULL');
        DB::statement('CREATE INDEX device_tokens_personal_access_token_id_index ON device_tokens (personal_access_token_id) WHERE personal_access_token_id IS NOT NULL');
        DB::statement('CREATE INDEX campaign_participations_delivery_address_id_index ON campaign_participations (delivery_address_id) WHERE delivery_address_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX campaign_participations_delivery_address_id_index');
        DB::statement('DROP INDEX device_tokens_personal_access_token_id_index');
        DB::statement('DROP INDEX refunds_customer_order_id_index');
    }
};
