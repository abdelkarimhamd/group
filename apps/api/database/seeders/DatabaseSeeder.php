<?php

namespace Database\Seeders;

use App\Modules\Identity\Permissions\AdminPermissions;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data every environment needs. Admins are created with
     * `php artisan admin:create`, never seeded with a known password.
     */
    public function run(): void
    {
        AdminPermissions::sync();
    }
}
