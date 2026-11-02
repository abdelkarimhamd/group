<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Permissions\AdminPermissions;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Phone\SaudiMobile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates an admin from the command line. This is how the first admin is
 * made; admins.manage in the portal comes later. The password is asked for
 * interactively so it never lands in shell history.
 */
final class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create
        {email : Sign-in email}
        {--name= : Full name}
        {--phone= : Saudi mobile that receives sign-in codes}
        {--role=* : One or more of: super_admin, sourcing, campaign_manager, finance, operations, support}';

    protected $description = 'Create an admin who can sign in to the admin portal';

    public function handle(): int
    {
        $input = [
            'email' => mb_strtolower(trim((string) $this->argument('email'))),
            'name' => $this->option('name') ?? $this->ask('Full name'),
            'phone' => SaudiMobile::normalize($this->option('phone') ?? $this->ask('Saudi mobile for sign-in codes')),
            'roles' => $this->option('role'),
            'password' => $this->secret('Password (at least 12 characters)'),
        ];

        $validator = Validator::make($input, [
            'email' => ['required', 'email', 'max:191', Rule::unique('admin_users', 'email')],
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::enum(AdminRole::class)],
            'password' => ['required', 'string', 'min:12', 'max:200'],
        ], [
            'phone.required' => 'The phone must be a Saudi mobile number, for example 0512345678.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        AdminPermissions::sync();

        $admin = DB::transaction(function () use ($input): AdminUser {
            $admin = AdminUser::create([
                'email' => $input['email'],
                'name' => $input['name'],
                'phone_e164' => $input['phone'],
                'password' => $input['password'],
            ]);
            $admin->syncRoles($input['roles']);

            app(AuditLogger::class)->record(Actor::system(), 'admin.create', $admin, after: [
                'email' => $admin->email,
                'roles' => $input['roles'],
                'via' => 'console',
            ]);

            return $admin;
        });

        $this->info("Created admin {$admin->email} with roles: ".implode(', ', $input['roles']).'.');

        return self::SUCCESS;
    }
}
