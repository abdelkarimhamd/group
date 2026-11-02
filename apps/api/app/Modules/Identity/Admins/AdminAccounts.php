<?php

namespace App\Modules\Identity\Admins;

use App\Exceptions\ApiException;
use App\Modules\Identity\Enums\AdminStatus;
use App\Modules\Identity\Enums\OtpPurpose;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Identity\Models\OtpChallenge;
use App\Modules\Identity\Permissions\AdminPermissions;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\StatusHistory;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Creating and changing admin accounts, from the portal or the console.
 *
 * Every change is audited (passwords never appear in the audit, only the fact
 * that one was set), status changes get a status history row, and blocking an
 * admin, setting their password or moving their phone signs them out
 * everywhere and cancels any sign-in in progress.
 *
 * In the portal nobody can hand out more than they hold: an admin can grant
 * only roles whose permissions they have, and can only block (not otherwise
 * change) an admin who holds a permission they lack. So a super admin cannot
 * make or take over a finance account and approve their own refunds. Those
 * changes are made on the server, through the console commands, where `$by`
 * is null.
 */
final class AdminAccounts
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StatusHistory $history,
    ) {}

    /**
     * @param  array{email: string, name: string, phone: string, password: string, roles: list<string>}  $input
     * @param  AdminUser|null  $by  The admin creating the account, or null from the console.
     *
     * @throws ApiException cannot_grant_role (403) when $by lacks a permission of a requested role
     * @throws ValidationException when the email or phone was taken meanwhile
     */
    public function create(array $input, ?AdminUser $by): AdminUser
    {
        AdminPermissions::ensureSynced();

        if ($by !== null) {
            $this->ensureCanGrant($by, $input['roles']);
        }

        return $this->uniquely(fn (): AdminUser => DB::transaction(function () use ($input, $by): AdminUser {
            $admin = AdminUser::create([
                'email' => $input['email'],
                'name' => $input['name'],
                'phone_e164' => $input['phone'],
                'password' => $input['password'],
            ]);
            $admin->syncRoles($input['roles']);

            $this->audit->record($this->actor($by), 'admin.create', $admin, after: [
                'email' => $admin->email,
                'name' => $admin->name,
                'phone' => $admin->phone_e164,
                'roles' => $this->sorted($input['roles']),
                'via' => $by === null ? 'console' : 'portal',
            ]);

            return $admin;
        }));
    }

    /**
     * @param  array{name?: string, phone?: string, roles?: list<string>, status?: string, password?: string}  $changes
     * @param  AdminUser|null  $by  The admin making the change, or null from the console.
     *
     * @throws ApiException cannot_change_own_access (422) when an admin changes their own roles, status or password
     * @throws ApiException cannot_manage_admin (403) when the target holds a permission $by lacks and the change is not a block
     * @throws ApiException cannot_grant_role (403) when $by lacks a permission of a requested role
     * @throws ValidationException when the phone was taken meanwhile
     */
    public function update(AdminUser $admin, array $changes, ?AdminUser $by): AdminUser
    {
        if ($by !== null && $admin->is($by) && array_intersect_key($changes, array_flip(['roles', 'status', 'password'])) !== []) {
            throw ApiException::unprocessable('cannot_change_own_access');
        }

        return $this->uniquely(fn (): AdminUser => DB::transaction(function () use ($admin, $changes, $by): AdminUser {
            /** @var AdminUser $admin */
            $admin = AdminUser::query()->whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $actor = $this->actor($by);
            $before = $after = [];

            if ($by !== null) {
                $this->ensureCanManage($by, $admin, $changes);

                if (array_key_exists('roles', $changes)) {
                    $this->ensureCanGrant($by, $changes['roles']);
                }
            }

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
            // unblocking later does not bring old sessions back. A sign-in
            // started with the old password or phone must not finish either.
            $blocked = $admin->status === AdminStatus::Blocked && $fromStatus !== AdminStatus::Blocked;

            if ($blocked || isset($after['password_reset']) || isset($after['phone'])) {
                $after['signed_out_tokens'] = $admin->tokens()->delete();
                $this->cancelSignIns($admin);
            }

            if ($after !== []) {
                $this->audit->record($actor, 'admin.update', $admin, $before, $after);
            }

            return $admin;
        }));
    }

    /**
     * Change the signed-in admin's own password. Their other sessions end, and
     * so does any sign-in in progress; the token making this request keeps
     * working.
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
            $this->cancelSignIns($admin);

            $this->audit->record(Actor::admin($admin->id), 'admin.password_change', $admin, after: [
                'signed_out_tokens' => $signedOut,
            ]);
        });
    }

    /**
     * @param  list<string>  $roles
     *
     * @throws ApiException cannot_grant_role (403)
     */
    private function ensureCanGrant(AdminUser $by, array $roles): void
    {
        $catalogue = AdminPermissions::roles();
        $wanted = array_merge(...array_map(fn (string $role) => $catalogue[$role] ?? [], array_values($roles)));

        if ($this->missing($by, $wanted) !== []) {
            throw new ApiException(403, 'cannot_grant_role');
        }
    }

    /**
     * An admin who holds a permission $by lacks can only be blocked by $by.
     *
     * @param  array<string, mixed>  $changes
     *
     * @throws ApiException cannot_manage_admin (403)
     */
    private function ensureCanManage(AdminUser $by, AdminUser $target, array $changes): void
    {
        $blockOnly = array_keys($changes) === ['status'] && $changes['status'] === AdminStatus::Blocked->value;

        if (! $blockOnly && $this->missing($by, $target->getAllPermissions()->pluck('name')->all()) !== []) {
            throw new ApiException(403, 'cannot_manage_admin');
        }
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string> The permissions $admin does not hold.
     */
    private function missing(AdminUser $admin, array $permissions): array
    {
        return array_values(array_diff($permissions, $admin->getAllPermissions()->pluck('name')->all()));
    }

    /**
     * Ends the admin's unfinished sign-ins: a code sent before the change can
     * no longer be used.
     */
    private function cancelSignIns(AdminUser $admin): void
    {
        OtpChallenge::query()
            ->where('admin_user_id', $admin->id)
            ->where('purpose', OtpPurpose::AdminLogin)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);
    }

    private function actor(?AdminUser $by): Actor
    {
        return $by === null ? Actor::system() : Actor::admin($by->id);
    }

    /**
     * The form request checks email and phone are free, but another request
     * can take them before this one commits. Answer that as a validation
     * error, not a server error.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function uniquely(Closure $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            $field = str_contains($e->getMessage(), 'phone_e164') ? 'phone' : 'email';

            throw ValidationException::withMessages([$field => [__('validation.unique', ['attribute' => $field])]]);
        }
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
