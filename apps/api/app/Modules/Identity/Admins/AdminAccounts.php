<?php

namespace App\Modules\Identity\Admins;

use App\Exceptions\ApiException;
use App\Modules\Identity\Enums\AdminStatus;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Permissions\AdminPermissions;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Creating and changing admin accounts, from the portal or the console.
 *
 * Every change is audited (passwords never appear in the audit, only the fact
 * that one was set), status changes get a status history row, and blocking an
 * admin or setting their password signs them out everywhere.
 */
final class AdminAccounts
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StatusHistory $history,
    ) {}

    /**
     * @param  array{email: string, name: string, phone: string, password: string, roles: list<string>}  $input
     */
    public function create(array $input, Actor $by, string $via): AdminUser
    {
        AdminPermissions::ensureSynced();

        return DB::transaction(function () use ($input, $by, $via): AdminUser {
            $admin = AdminUser::create([
                'email' => $input['email'],
                'name' => $input['name'],
                'phone_e164' => $input['phone'],
                'password' => $input['password'],
            ]);
            $admin->syncRoles($input['roles']);

            $this->audit->record($by, 'admin.create', $admin, after: [
                'email' => $admin->email,
                'name' => $admin->name,
                'phone' => $admin->phone_e164,
                'roles' => $this->sorted($input['roles']),
                'via' => $via,
            ]);

            return $admin;
        });
    }

    /**
     * @param  array{name?: string, phone?: string, roles?: list<string>, status?: string, password?: string}  $changes
     *
     * @throws ApiException cannot_change_own_access when an admin changes their own roles, status or password
     */
    public function update(AdminUser $admin, array $changes, AdminUser $by): AdminUser
    {
        if ($admin->is($by) && array_intersect_key($changes, array_flip(['roles', 'status', 'password'])) !== []) {
            throw ApiException::unprocessable('cannot_change_own_access');
        }

        return DB::transaction(function () use ($admin, $changes, $by): AdminUser {
            /** @var AdminUser $admin */
            $admin = AdminUser::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $actor = Actor::admin($by->id);
            $before = $after = [];

            foreach (['name' => 'name', 'phone' => 'phone_e164'] as $field => $column) {
                if (array_key_exists($field, $changes) && $changes[$field] !== $admin->{$column}) {
                    $before[$field] = $admin->{$column};
                    $after[$field] = $admin->{$column} = $changes[$field];
                }
            }

            $fromStatus = $admin->status;

            if (array_key_exists('status', $changes) && $changes['status'] !== $fromStatus->value) {
                $admin->status = AdminStatus::from($changes['status']);
                $before['status'] = $fromStatus->value;
                $after['status'] = $admin->status->value;
            }

            if (array_key_exists('password', $changes)) {
                $admin->password = $changes['password'];
                $after['password_reset'] = true;
            }

            $admin->save();

            if ($admin->status !== $fromStatus) {
                $this->history->record($admin, $fromStatus, $admin->status, $actor);
            }

            if (array_key_exists('roles', $changes)) {
                $current = $this->sorted($admin->getRoleNames()->all());
                $wanted = $this->sorted($changes['roles']);

                if ($current !== $wanted) {
                    AdminPermissions::ensureSynced();
                    $admin->syncRoles($wanted);
                    $before['roles'] = $current;
                    $after['roles'] = $wanted;
                }
            }

            // A blocked admin's tokens are refused anyway; deleting them means
            // unblocking later does not bring old sessions back.
            if (isset($after['password_reset']) || ($admin->status === AdminStatus::Blocked && $fromStatus !== AdminStatus::Blocked)) {
                $after['signed_out_tokens'] = $admin->tokens()->delete();
            }

            if ($after !== []) {
                $this->audit->record($actor, 'admin.update', $admin, $before, $after);
            }

            return $admin;
        });
    }

    /**
     * Change the signed-in admin's own password. Their other sessions end;
     * the token making this request keeps working.
     *
     * @throws ApiException invalid_credentials when the current password is wrong
     */
    public function changeOwnPassword(AdminUser $admin, string $current, string $new): void
    {
        if (! Hash::check($current, $admin->password)) {
            throw ApiException::unprocessable('invalid_credentials', 'current_password');
        }

        DB::transaction(function () use ($admin, $new): void {
            $admin->password = $new;
            $admin->save();

            $currentToken = $admin->currentAccessToken();
            $signedOut = $admin->tokens()
                ->when($currentToken?->getKey(), fn ($query, $id) => $query->whereKeyNot($id))
                ->delete();

            $this->audit->record(Actor::admin($admin->id), 'admin.password_change', $admin, after: [
                'signed_out_tokens' => $signedOut,
            ]);
        });
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function sorted(array $roles): array
    {
        $roles = array_values(array_unique($roles));
        sort($roles);

        return $roles;
    }
}
