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
 * Separation of duties: the permissions that release or adjust money
 * (approvals.decide, refunds.approve, ledger.adjust) belong to finance only,
 * not super_admin, so no single all-powerful account can move money alone.
 */
final class AdminPermissions
{
    /**
     * Every permission, with what it allows.
     */
    public const ALL = [
        'catalog.view' => 'See products, variants and categories',
        'catalog.edit' => 'Create and change products, variants, photos and categories',
        'suppliers.view' => 'See suppliers and quotations',
        'suppliers.edit' => 'Create and change suppliers and quotations',
        'campaigns.view' => 'See campaigns, participations and events',
        'campaigns.edit' => 'Create and change draft campaigns',
        'pricing.edit' => 'Set price tiers',
        'campaigns.approve' => 'Approve a campaign to go live',
        'campaigns.extend' => 'Use the one allowed deadline extension',
        'campaigns.cancel' => 'Ask to cancel a campaign (needs an approval)',
        'campaigns.close' => 'Run closing steps: supplier reconfirmation and capture',
        'payments.view' => 'See holds, charges and Moyasar calls',
        'payments.manage' => 'Retry a capture or void a hold',
        'refunds.request' => 'Ask for a refund (needs an approval)',
        'refunds.approve' => 'Approve a refund',
        'approvals.decide' => 'Approve or reject requests made by another admin',
        'orders.view' => 'See customer orders',
        'orders.edit' => 'Change customer orders',
        'purchasing.view' => 'See supplier purchase orders',
        'purchasing.edit' => 'Create and change supplier purchase orders',
        'fulfilment.view' => 'See warehouse receipts and shipments',
        'fulfilment.edit' => 'Record receipts and shipments',
        'finance.reconcile' => 'Run and resolve Moyasar reconciliation',
        'ledger.adjust' => 'Post manual ledger adjustments',
        'audit.view' => 'Read the audit log',
        'customers.view' => 'See customer accounts',
        'customers.block' => 'Block or unblock a customer',
        'admins.manage' => 'Create admins and change their roles',
    ];

    /**
     * Permissions only finance may hold.
     */
    public const FINANCE_ONLY = ['approvals.decide', 'refunds.approve', 'ledger.adjust'];

    /**
     * @return array<string, list<string>> role name => permission names
     */
    public static function roles(): array
    {
        return [
            AdminRole::SuperAdmin->value => array_values(array_diff(array_keys(self::ALL), self::FINANCE_ONLY)),

            AdminRole::Sourcing->value => [
                'catalog.view', 'catalog.edit', 'suppliers.view', 'suppliers.edit',
                'campaigns.view', 'purchasing.view', 'purchasing.edit',
            ],

            AdminRole::CampaignManager->value => [
                'catalog.view', 'suppliers.view', 'campaigns.view', 'campaigns.edit', 'pricing.edit',
                'campaigns.extend', 'campaigns.cancel', 'campaigns.close', 'payments.view', 'orders.view',
            ],

            AdminRole::Finance->value => [
                'campaigns.view', 'campaigns.approve', 'suppliers.view', 'payments.view', 'payments.manage',
                'refunds.request', 'refunds.approve', 'approvals.decide', 'orders.view', 'purchasing.view',
                'finance.reconcile', 'ledger.adjust', 'audit.view', 'customers.view',
            ],

            AdminRole::Operations->value => [
                'catalog.view', 'suppliers.view', 'campaigns.view', 'orders.view', 'orders.edit',
                'purchasing.view', 'purchasing.edit', 'fulfilment.view', 'fulfilment.edit', 'customers.view',
            ],

            AdminRole::Support->value => [
                'campaigns.view', 'orders.view', 'payments.view', 'refunds.request',
                'fulfilment.view', 'customers.view',
            ],
        ];
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
