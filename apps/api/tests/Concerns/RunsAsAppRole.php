<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Artisan;
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
     * Run the rest of the test as a role with exactly the grants
     * db:grant-app-role gives production's app role. The role and the
     * switch are undone when the test's transaction rolls back.
     */
    protected function runAsAppRole(): void
    {
        DB::statement('CREATE ROLE groupbuy_test_app NOLOGIN');
        Artisan::call('db:grant-app-role', ['role' => 'groupbuy_test_app']);
        DB::statement('SET LOCAL ROLE groupbuy_test_app');
    }
}
