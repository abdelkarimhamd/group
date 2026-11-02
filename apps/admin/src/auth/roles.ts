import type { AdminUser } from '../api/types';
import { missingPermissions } from './permissions';

/**
 * How much `me` may change `target` through PATCH /admin/admins/{id}:
 * - `self`: only name and phone (the API refuses your own roles, status and
 *   password with cannot_change_own_access).
 * - `block_only`: the target holds a permission `me` lacks, so the only
 *   change allowed is blocking them (otherwise 403 cannot_manage_admin).
 * - `full`: anything, within the roles GET /admin/roles marks grantable.
 */
export type AdminAccess = 'self' | 'block_only' | 'full';

export function adminAccess(me: AdminUser | undefined, target: AdminUser): AdminAccess {
  if (me && me.id === target.id) return 'self';
  return missingPermissions(me, target.permissions).length > 0 ? 'block_only' : 'full';
}
