import { isApiError } from '@/api/errors';
import type { AddressInput, User } from '@/api/types';
import type { Locale } from '@/i18n/locale';

/** The API asks for consent again whenever the policy version moves on (PDPL). */
export function needsPrivacyConsent(user: User): boolean {
  return (
    user.privacy_consent === null ||
    user.privacy_consent.policy_version !== user.current_privacy_policy_version
  );
}

/** "Olaya St, Al Olaya, Riyadh 12211" in the reading order of the language. */
export function addressLine(address: AddressInput, locale: Locale): string {
  const separator = locale === 'ar' ? '، ' : ', ';
  const parts = [
    address.building_no ? `${address.building_no} ${address.street}` : address.street,
    address.district,
    `${address.city} ${address.postal_code}`,
  ];
  return parts.filter(Boolean).join(separator);
}

/** Why deleting the account is not possible yet, in a fixed order. */
export type DeletionBlocker = 'joins' | 'orders' | 'payments';
const BLOCKERS: DeletionBlocker[] = ['joins', 'orders', 'payments'];

/**
 * What a 409 `account_deletion_blocked` from DELETE /me says is still open, or null for any
 * other error (or none). Unknown reasons are dropped; an empty list still means "blocked".
 */
export function deletionBlockers(error: unknown): DeletionBlocker[] | null {
  if (!isApiError(error) || error.code !== 'account_deletion_blocked') return null;
  const body = error.body as { blocked_by?: unknown } | null;
  const listed = Array.isArray(body?.blocked_by) ? body.blocked_by : [];
  return BLOCKERS.filter((reason) => listed.includes(reason));
}
