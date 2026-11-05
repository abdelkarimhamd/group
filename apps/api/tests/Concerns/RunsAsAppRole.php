<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Production's grants in a test. Tests connect as a superuser, which may
 * lock and change any row; in production the app's role may only SELECT
 * and INSERT on history tables (docs/architecture.md), so a row lock there
 * (FOR UPDATE needs the UPDATE privilege) fails only in production.
 */
trait RunsAsAppRole
{
    /**
     * Run the rest of the test as a role with production's grants: all of
     * them on other tables, only SELECT and INSERT on every table
     * AppendOnly protects. The role and the switch are undone when the
     * test's transaction rolls back.
     */
    protected function runAsAppRole(): void
    {
        $history = collect(DB::select("SELECT DISTINCT event_object_table AS name FROM information_schema.triggers WHERE trigger_name LIKE '%\\_append\\_only\\_rows'"))
            ->pluck('name')
            ->map(fn (string $table) => '"'.$table.'"')
            ->implode(', ');

        DB::statement('CREATE ROLE groupbuy_test_app NOLOGIN');
        DB::statement('GRANT ALL ON ALL TABLES IN SCHEMA public TO groupbuy_test_app');
        DB::statement('GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO groupbuy_test_app');
        DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON {$history} FROM groupbuy_test_app");
        DB::statement('SET LOCAL ROLE groupbuy_test_app');
    }
}
