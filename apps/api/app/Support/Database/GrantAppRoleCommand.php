<?php

namespace App\Support\Database;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives the runtime database role production's grants (docs/runbook.md).
 *
 * Migrations run as the owner role, which owns every table. The app runs as
 * a second role that may read and write ordinary tables but only SELECT and
 * INSERT on history tables (AppendOnly), and may not change the schema or
 * the migrations table. Run it as the owner after every migration, so new
 * tables get their grants: the API image's migrate role does.
 */
final class GrantAppRoleCommand extends Command
{
    protected $signature = 'db:grant-app-role {role : The PostgreSQL role the app connects as}';

    protected $description = 'Grant the app database role what it needs, and only SELECT and INSERT on history tables';

    public function handle(): int
    {
        $role = (string) $this->argument('role');

        if (! preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role)) {
            $this->error('The role must be a lowercase PostgreSQL name, for example groupbuy_app.');

            return self::FAILURE;
        }

        if (DB::scalar('SELECT count(*) FROM pg_roles WHERE rolname = ?', [$role]) === 0) {
            $this->error("There is no role {$role}. Create it first (deploy/postgres/roles.sql).");

            return self::FAILURE;
        }

        if ($role === DB::scalar('SELECT current_user')) {
            $this->error('Run this as the owner role, for a different role the app connects as.');

            return self::FAILURE;
        }

        $schema = (string) DB::scalar('SELECT current_schema()');
        $history = AppendOnly::tables();
        $quoted = '"'.$role.'"';
        $in = '"'.$schema.'"';

        DB::transaction(function () use ($quoted, $in, $history): void {
            DB::statement("GRANT USAGE ON SCHEMA {$in} TO {$quoted}");
            DB::statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {$in} TO {$quoted}");
            DB::statement("GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA {$in} TO {$quoted}");
            DB::statement("REVOKE CREATE ON SCHEMA {$in} FROM {$quoted}");
            DB::statement("REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON {$in}.\"migrations\" FROM {$quoted}");

            if ($history !== []) {
                $tables = collect($history)->map(fn (string $table) => $in.'."'.$table.'"')->implode(', ');
                DB::statement("REVOKE UPDATE, DELETE, TRUNCATE ON {$tables} FROM {$quoted}");
            }
        });

        $this->info(sprintf(
            '%s may now use every table in %s; %d history tables are SELECT and INSERT only.',
            $role,
            $schema,
            count($history),
        ));

        return self::SUCCESS;
    }
}
