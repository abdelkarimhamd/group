<?php

namespace App\Modules\Identity\Permissions;

use App\Modules\Identity\Enums\AdminRole;
use App\Modules\Identity\Models\AdminUser;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The admin permission catalogue and what each role holds.
 *
 * This class is the source of truth; sync() writes it to the database
 * (run on deploy via `php artisan admin:sync-roles`, and by admin:create).
 * Each admin endpoint checks a permission, never a role name, so a role can
 * change here without touching endpoints.
 *
 * Separation of duties: approvals.decide (approving refunds, manual
 * captures and voids, ledger adjustments and campaign cancellations requested
 * by another admin) belongs to finance only, not super_admin, so releasing
 * money always takes finance.
 */
final class AdminPermissions
{
    /**
     * Every permission, with what it allows. Each admin operation in
     * openapi/v1.yaml names the one it needs in `x-permission` (and any extra
     * one a particular field needs in `x-field-permissions`), and a contract
     * test keeps this list and the contract in step.
     */
    public const ALL = [
        'catalog.view' => 'See categories, products, variants and photos',
        'catalog.edit' => 'Create and change categories, products, variants and photos',
        'suppliers.view' => 'See suppliers and quotations',
        'suppliers.edit' => 'Create and change suppliers and quotations',
        'suppliers.approve' => 'Approve or block a supplier',
        'campaigns.view' => 'See campaigns, margins, participations and history',
        'campaigns.edit' => 'Create, edit and submit draft campaigns',
        'pricing.edit' => 'Set price tiers',
        'campaigns.approve' => 'Approve a campaign to go live, or send it back',
        'campaigns.override_margin' => 'Approve a campaign below the margin floor, giving a reason',
        'campaigns.extend' => 'Use the one allowed deadline extension',
        'campaigns.cancel' => 'Cancel a campaign before any capture (after one, it needs an approval)',
        'campaigns.close' => 'Record the supplier reconfirmation and below-target decisions',
        'payments.view' => 'See holds, charges, refunds and Moyasar calls',
        'refunds.request' => 'Ask for a refund (needs an approval)',
        'approvals.view' => 'See approval requests',
        'approvals.decide' => 'Approve or reject a request made by another admin',
        'orders.view' => 'See customer orders and shipments',
        'fulfilment.edit' => 'Record warehouse receipts, shipments and tracking updates',
        'purchasing.view' => 'See supplier purchase orders',
        'purchasing.edit' => 'Record and advance supplier purchase orders',
        'reconciliation.view' => 'See reconciliation runs and mismatches',
        'reconciliation.resolve' => 'Close a reconciliation mismatch with a note',
        'audit.view' => 'Read the audit log',
        'admins.manage' => 'Create admins, change their roles and block them',
    ];

    /**
     * Permissions only finance may hold.
     */
    public const FINANCE_ONLY = ['approvals.decide'];

    /**
     * @return array<string, list<string>> role name => permission names
     */
    public static function roles(): array
    {
        return [
            AdminRole::SuperAdmin->value => array_values(array_diff(array_keys(self::ALL), self::FINANCE_ONLY)),

            AdminRole::Sourcing->value => [
                'catalog.view', 'catalog.edit', 'suppliers.view', 'suppliers.edit',
                'campaigns.view', 'orders.view', 'purchasing.view', 'purchasing.edit',
            ],

            AdminRole::CampaignManager->value => [
                'catalog.view', 'suppliers.view', 'campaigns.view', 'campaigns.edit', 'pricing.edit',
                'campaigns.extend', 'campaigns.cancel', 'campaigns.close', 'payments.view',
                'approvals.view', 'orders.view',
            ],

            AdminRole::Finance->value => [
                'catalog.view', 'suppliers.view', 'suppliers.approve', 'campaigns.view', 'campaigns.approve',
                'payments.view', 'refunds.request', 'approvals.view', 'approvals.decide', 'orders.view',
                'purchasing.view', 'reconciliation.view', 'reconciliation.resolve', 'audit.view',
            ],

            AdminRole::Operations->value => [
                'catalog.view', 'suppliers.view', 'campaigns.view', 'orders.view', 'fulfilment.edit',
                'purchasing.view', 'purchasing.edit',
            ],

            AdminRole::Support->value => [
                'campaigns.view', 'orders.view', 'payments.view', 'refunds.request', 'approvals.view',
            ],
        ];
    }

    /**
     * Sync only if a role is missing, for example on a fresh database where
     * admin:sync-roles has not run yet.
     */
    public static function ensureSynced(): void
    {
        $existing = Role::query()->where('guard_name', AdminUser::GUARD)->pluck('name')->all();

        if (array_diff(array_keys(self::roles()), $existing) !== []) {
            self::sync();
        }
    }

    /**
     * Write the catalogue and role map to the database. Safe to run any number
     * of times; permissions no longer in the catalogue are removed.
     */
    public static function sync(): void
    {
        DB::transaction(function (): void {
            $guard = AdminUser::GUARD;

            foreach (array_keys(self::ALL) as $name) {
                Permission::findOrCreate($name, $guard);
            }

            Permission::query()
                ->where('guard_name', $guard)
                ->whereNotIn('name', array_keys(self::ALL))
                ->delete();

            foreach (self::roles() as $role => $permissions) {
                Role::findOrCreate($role, $guard)->syncPermissions($permissions);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
