<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A campaign's version grows by one with every UPDATE of its row, set by
     * PostgreSQL itself, so no write path (Eloquent or a conditional UPDATE)
     * can forget it. Row locks order the updates, so a higher version is
     * always the later state. Customers' clients compare it to tell a newer
     * progress payload from an older one; updated_at has whole seconds only.
     * CREATE OR REPLACE: migrate:fresh drops tables but keeps functions.
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->bigInteger('version')->default(0);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION campaigns_bump_version() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                NEW.version := OLD.version + 1;
                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER campaigns_bump_version
                BEFORE UPDATE ON campaigns
                FOR EACH ROW EXECUTE FUNCTION campaigns_bump_version();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS campaigns_bump_version ON campaigns;
            DROP FUNCTION IF EXISTS campaigns_bump_version();
            SQL);

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
