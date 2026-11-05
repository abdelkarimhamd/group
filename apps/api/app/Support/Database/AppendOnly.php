<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Database-level protection for history tables (audit logs, status history,
 * ledger entries). PostgreSQL triggers reject UPDATE, DELETE and TRUNCATE from
 * the application, raw queries and console sessions that use the app's role.
 *
 * The triggers stop mistakes, not a determined table owner: the owner can drop
 * or disable them. In production, migrations run as the owner role, and the
 * runtime role gets only SELECT and INSERT on history tables (plus USAGE on
 * their sequences). Grants are the boundary; the triggers are the safety net.
 */
final class AppendOnly
{
    public const FUNCTION_NAME = 'reject_append_only_mutation';

    /**
     * Create the shared trigger function. Safe to run more than once.
     */
    public static function createFunction(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION reject_append_only_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Table % is append-only: % is not allowed', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }

    public static function dropFunction(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS reject_append_only_mutation()');
    }

    public static function protect(string $table): void
    {
        self::assertSafeIdentifier($table);

        DB::unprepared(<<<SQL
            CREATE TRIGGER {$table}_append_only_rows
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION reject_append_only_mutation();
            CREATE TRIGGER {$table}_append_only_truncate
                BEFORE TRUNCATE ON {$table}
                FOR EACH STATEMENT EXECUTE FUNCTION reject_append_only_mutation();
            SQL);
    }

    /**
     * Every table protect() guards in the current schema, by name.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        return collect(DB::select(<<<'SQL'
            SELECT DISTINCT event_object_table AS name
            FROM information_schema.triggers
            WHERE trigger_schema = current_schema() AND trigger_name LIKE '%\_append\_only\_rows'
            ORDER BY 1
            SQL))
            ->pluck('name')
            ->values()
            ->all();
    }

    public static function release(string $table): void
    {
        self::assertSafeIdentifier($table);

        DB::unprepared(<<<SQL
            DROP TRIGGER IF EXISTS {$table}_append_only_rows ON {$table};
            DROP TRIGGER IF EXISTS {$table}_append_only_truncate ON {$table};
            SQL);
    }

    private static function assertSafeIdentifier(string $table): void
    {
        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $table)) {
            throw new InvalidArgumentException("Unsafe table name [{$table}].");
        }
    }
}
