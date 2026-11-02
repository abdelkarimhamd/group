import type { components } from '../api/schema';

/**
 * Permission names, generated from the contract's AdminPermission enum. The
 * portal only hides what an admin cannot do; the API enforces every one of
 * them. Always read them from GET /admin/me and never infer them from role
 * names: super_admin, for one, does not hold approvals.decide, which belongs
 * to finance.
 */
export type Permission = components['schemas']['AdminPermission'];

export function hasPermission(
  admin: { permissions: readonly string[] } | null | undefined,
  permission: Permission,
): boolean {
  return admin?.permissions.includes(permission) ?? false;
}

/** Every permission in `needed` that the admin lacks. */
export function missingPermissions(
  admin: { permissions: readonly string[] } | null | undefined,
  needed: readonly string[],
): string[] {
  const held = new Set(admin?.permissions ?? []);
  return needed.filter((permission) => !held.has(permission));
}
