import { describe, expect, it } from 'vitest';
import { adminUser } from '../test/fixtures';
import { hasPermission, missingPermissions } from './permissions';
import { adminAccess } from './roles';

const superAdmin = adminUser({
  id: 'a',
  roles: ['super_admin'],
  permissions: ['admins.manage', 'campaigns.view', 'catalog.view', 'campaigns.override_margin'],
});

describe('permissions', () => {
  it('reads permissions, never roles', () => {
    expect(hasPermission(superAdmin, 'campaigns.view')).toBe(true);
    // A super admin does not hold approvals.decide; finance does.
    expect(hasPermission(superAdmin, 'approvals.decide')).toBe(false);
    expect(hasPermission(undefined, 'campaigns.view')).toBe(false);
  });

  it('lists what is missing', () => {
    expect(missingPermissions(superAdmin, ['campaigns.view', 'approvals.decide'])).toEqual([
      'approvals.decide',
    ]);
  });
});

describe('adminAccess', () => {
  it('limits changes to yourself', () => {
    expect(adminAccess(superAdmin, superAdmin)).toBe('self');
  });

  it('only allows blocking an admin who holds a permission you lack', () => {
    const finance = adminUser({
      id: 'b',
      roles: ['finance'],
      permissions: ['approvals.decide', 'campaigns.view'],
    });
    expect(adminAccess(superAdmin, finance)).toBe('block_only');
  });

  it('allows everything for an admin whose permissions you hold', () => {
    const viewer = adminUser({ id: 'c', roles: ['support'], permissions: ['campaigns.view'] });
    expect(adminAccess(superAdmin, viewer)).toBe('full');
  });
});
