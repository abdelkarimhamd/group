import Constants from 'expo-constants';
import { Platform } from 'react-native';

/** Only the fake form exists until the Moyasar integration lands. */
export type PaymentProviderName = 'fake';

/**
 * Without EXPO_PUBLIC_API_URL the app talks to the Prism mock (`npm run mock`) on the
 * machine that runs Metro, so a phone on the same Wi-Fi works without extra setup.
 */
function defaultApiUrl(): string {
  const host = Constants.expoConfig?.hostUri?.split(':')[0];
  if (host && host !== 'localhost' && host !== '127.0.0.1') {
    return `http://${host}:4010`;
  }
  return Platform.OS === 'android' ? 'http://10.0.2.2:4010' : 'http://localhost:4010';
}

export const config = {
  apiUrl: (process.env.EXPO_PUBLIC_API_URL || defaultApiUrl()).replace(/\/+$/, ''),
  paymentProvider: 'fake' as PaymentProviderName,
  privacyPolicyUrl: process.env.EXPO_PUBLIC_PRIVACY_POLICY_URL || null,
  /** How often an open campaign page polls `/progress` until Reverb push lands. */
  progressPollMs: 5_000,
} as const;
