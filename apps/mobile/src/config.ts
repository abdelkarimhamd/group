import Constants from 'expo-constants';
import { Platform } from 'react-native';

/**
 * Which payment form the app shows. `fake` (the default) hands back the API's fake provider
 * IDs, which only an API in fake payment mode accepts. `moyasar` opens Moyasar's form on the
 * website's /payments/app page inside the app.
 */
export type PaymentProviderName = 'fake' | 'moyasar';

export function paymentProvider(
  value: string | undefined = process.env.EXPO_PUBLIC_PAYMENT_PROVIDER,
): PaymentProviderName {
  return value === 'moyasar' ? 'moyasar' : 'fake';
}

/**
 * Without EXPO_PUBLIC_API_URL the app talks to the Prism mock (`npm run mock`) on the
 * machine that runs Metro, so a phone on the same Wi-Fi works without extra setup.
 */
function devServer(port: number): string {
  const host = Constants.expoConfig?.hostUri?.split(':')[0];
  if (host && host !== 'localhost' && host !== '127.0.0.1') {
    return `http://${host}:${port}`;
  }
  return Platform.OS === 'android' ? `http://10.0.2.2:${port}` : `http://localhost:${port}`;
}

function defaultApiUrl(): string {
  return devServer(4010);
}

export const config = {
  apiUrl: (process.env.EXPO_PUBLIC_API_URL || defaultApiUrl()).replace(/\/+$/, ''),
  paymentProvider: paymentProvider(),
  /**
   * The website, which hosts the Moyasar form the app shows. Its origin must be the API's
   * WEB_URL, because the form only accepts a checkout that returns to its own site. Without
   * EXPO_PUBLIC_WEB_URL: the website's dev server (port 3000) on the machine running Metro.
   */
  webUrl: (process.env.EXPO_PUBLIC_WEB_URL || devServer(3000)).replace(/\/+$/, ''),
  privacyPolicyUrl: process.env.EXPO_PUBLIC_PRIVACY_POLICY_URL || null,
  /** How often an open campaign page polls `/progress` until Reverb push lands. */
  progressPollMs: 5_000,
} as const;
