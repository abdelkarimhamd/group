<?php

namespace Tests\Feature\Support;

use App\Support\Database\AppendOnly;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\RunsAsAppRole;
use Tests\TestCase;

/**
 * The app's database role in production (docs/runbook.md): everything on
 * ordinary tables, only SELECT and INSERT on history tables, nothing on the
 * schema or the migrations table.
 */
class GrantAppRoleTest extends TestCase
{
    use RefreshDatabase, RunsAsAppRole;

    #[Test]
    public function the_app_role_may_only_read_and_add_to_history_tables(): void
    {
        DB::statement('CREATE ROLE groupbuy_test_grants NOLOGIN');

        $this->artisan('db:grant-app-role', ['role' => 'groupbuy_test_grants'])
            ->expectsOutputToContain(count(AppendOnly::tables()).' history tables are SELECT and INSERT only')
            ->assertSuccessful();

        $this->assertContains('audit_logs', AppendOnly::tables());
        $this->assertContains('ledger_entries', AppendOnly::tables());
        $this->assertNotContains('campaigns', AppendOnly::tables());

        $can = fn (string $table, string $privilege): bool => (bool) DB::scalar('SELECT has_table_privilege(?, ?, ?)', ['groupbuy_test_grants', $table, $privilege]);

        foreach (AppendOnly::tables() as $table) {
            $this->assertSame([true, true, false, false, false], [
                $can($table, 'SELECT'), $can($table, 'INSERT'), $can($table, 'UPDATE'), $can($table, 'DELETE'), $can($table, 'TRUNCATE'),
            ], $table);
        }

        $this->assertSame([true, true, true, true, false], [
            $can('campaigns', 'SELECT'), $can('campaigns', 'INSERT'), $can('campaigns', 'UPDATE'), $can('campaigns', 'DELETE'), $can('campaigns', 'TRUNCATE'),
        ]);
        $this->assertSame([true, false, false], [$can('migrations', 'SELECT'), $can('migrations', 'INSERT'), $can('migrations', 'UPDATE')]);
        $this->assertTrue((bool) DB::scalar("SELECT has_sequence_privilege('groupbuy_test_grants', 'campaigns_id_seq', 'USAGE')"));
        $this->assertFalse((bool) DB::scalar("SELECT has_schema_privilege('groupbuy_test_grants', current_schema(), 'CREATE')"));
    }

    #[Test]
    public function the_app_role_cannot_change_history_or_the_schema(): void
    {
        $this->runAsAppRole();

        $this->assertSame(0, DB::table('audit_logs')->count());
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('permission denied');

        DB::statement('CREATE TABLE sneaky (id int)');
    }

    #[Test]
    public function only_an_existing_role_other_than_the_owner_gets_grants(): void
    {
        $this->artisan('db:grant-app-role', ['role' => 'Robert"; DROP TABLE campaigns; --'])->assertFailed();
        $this->artisan('db:grant-app-role', ['role' => 'groupbuy_nobody'])->expectsOutputToContain('There is no role groupbuy_nobody')->assertFailed();
        $this->artisan('db:grant-app-role', ['role' => DB::scalar('SELECT current_user')])->assertFailed();
    }
}
