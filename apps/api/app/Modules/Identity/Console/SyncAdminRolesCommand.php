<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Permissions\AdminPermissions;
use Illuminate\Console\Command;

final class SyncAdminRolesCommand extends Command
{
    protected $signature = 'admin:sync-roles';

    protected $description = 'Write the admin roles and permissions from AdminPermissions to the database (run on every deploy)';

    public function handle(): int
    {
        AdminPermissions::sync();

        foreach (AdminPermissions::roles() as $role => $permissions) {
            $this->line(sprintf('%-17s %d permissions', $role, count($permissions)));
        }

        $this->info('Admin roles and permissions are up to date.');

        return self::SUCCESS;
    }
}
