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
