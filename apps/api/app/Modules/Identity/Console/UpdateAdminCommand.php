<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Admins\AdminAccounts;
use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Enums\AdminStatus;
use App\Modules\Identity\Models\AdminUser;
use App\Support\Phone\SaudiMobile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Changes an admin from the command line. The portal refuses changes to an
 * admin who holds a permission the person asking lacks (a finance admin, for a
 * super admin), so those are made here, by someone with server access.
 */
final class UpdateAdminCommand extends Command
{
    protected $signature = 'admin:update
        {email : The admin to change}
        {--name= : New full name}
        {--phone= : New Saudi mobile for sign-in codes}
        {--role=* : Replace the roles with these}
        {--status= : active or blocked}
        {--reset-password : Ask for a new password}';

    protected $description = 'Change an admin\'s details, roles, status or password';

    public function handle(AdminAccounts $accounts): int
    {
        $admin = AdminUser::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();

        if ($admin === null) {
            $this->error('No admin has that email.');

            return self::FAILURE;
        }

        $changes = array_filter([
            'name' => $this->option('name'),
            'phone' => $this->option('phone') === null ? null : (SaudiMobile::normalize($this->option('phone')) ?? ''),
            'roles' => $this->option('role') === [] ? null : array_values(array_unique($this->option('role'))),
            'status' => $this->option('status'),
            'password' => $this->option('reset-password') ? $this->secret('New password (at least 12 characters)') : null,
        ], fn ($value) => $value !== null);

        if ($changes === []) {
            $this->error('Nothing to change. Pass --name, --phone, --role, --status or --reset-password.');

            return self::FAILURE;
        }

        $validator = Validator::make($changes, [
            'name' => ['sometimes', 'string', 'max:100'],
            'phone' => ['sometimes', 'required', Rule::unique('admin_users', 'phone_e164')->ignore($admin->id)],
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => [Rule::enum(AdminRole::class)],
            'status' => ['sometimes', Rule::enum(AdminStatus::class)],
            'password' => ['sometimes', 'required', 'string', 'min:12', 'max:200'],
        ], [
            'phone.required' => 'The phone must be a Saudi mobile number, for example 0512345678.',
            'phone.unique' => 'Another admin already uses this phone number.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $accounts->update($admin, $changes, by: null);

        $this->info("Updated admin {$admin->email}: ".implode(', ', array_keys($changes)).'.');

        return self::SUCCESS;
    }
}
