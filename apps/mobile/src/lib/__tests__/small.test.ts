import { address, user } from '@/test/fixtures';

import { addressLine, needsPrivacyConsent } from '../account';
import { safeNextPath } from '../navigation';
import { ORDER_STEPS, orderStepIndex } from '../order';

describe('safeNextPath', () => {
  it('keeps in-app paths', () => {
    expect(safeNextPath('/campaign/abc')).toBe('/campaign/abc');
    expect(safeNextPath(['/joins', '/x'])).toBe('/joins');
  });

  it('drops anything that could leave the app', () => {
    expect(safeNextPath(undefined)).toBeNull();
    expect(safeNextPath('')).toBeNull();
    expect(safeNextPath('https://evil.example')).toBeNull();
    expect(safeNextPath('//evil.example')).toBeNull();
    expect(safeNextPath('/redirect?to=https://evil.example')).toBeNull();
    expect(safeNextPath('campaign/abc')).toBeNull();
  });
});

describe('orderStepIndex', () => {
  it('maps statuses onto the four steps', () => {
    expect(ORDER_STEPS).toHaveLength(4);
    expect(orderStepIndex('confirmed')).toBe(0);
    expect(orderStepIndex('ready_to_ship')).toBe(1);
    expect(orderStepIndex('shipped')).toBe(2);
    expect(orderStepIndex('completed')).toBe(3);
    expect(orderStepIndex('cancelled')).toBeNull();
    expect(orderStepIndex('returned')).toBeNull();
  });
});

describe('needsPrivacyConsent', () => {
  it('asks again when the policy version moved on', () => {
    expect(needsPrivacyConsent(user())).toBe(false);
    expect(needsPrivacyConsent(user({ privacy_consent: null }))).toBe(true);
    expect(needsPrivacyConsent(user({ current_privacy_policy_version: '2027-01' }))).toBe(true);
  });
});

describe('addressLine', () => {
  it('joins parts with the language comma', () => {
    const a = address({ street: 'Olaya St', district: 'Al Olaya', city: 'Riyadh' });
    expect(addressLine(a, 'en')).toBe('2401 Olaya St, Al Olaya, Riyadh 12211');
    expect(addressLine(address(), 'ar')).toBe('2401 شارع العليا العام، العليا، الرياض 12211');
  });
});
