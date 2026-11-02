import { getLocales } from 'expo-localization';
import { I18nManager, Platform } from 'react-native';

import { storage } from '@/lib/storage';

export type Locale = 'ar' | 'en';

export const LOCALES: readonly Locale[] = ['ar', 'en'];

const LOCALE_KEY = 'app.locale';
const RELOADED_FOR_KEY = 'app.directionReloadedFor';

export function isLocale(value: unknown): value is Locale {
  return value === 'ar' || value === 'en';
}

export function isRtl(locale: Locale): boolean {
  return locale === 'ar';
}

/** English when the phone is set to English; Arabic otherwise, as the API defaults to. */
export function deviceLocale(): Locale {
  return getLocales()[0]?.languageCode === 'en' ? 'en' : 'ar';
}

export function savedLocale(): Locale | null {
  const value = storage.get(LOCALE_KEY);
  return isLocale(value) ? value : null;
}

export function initialLocale(): Locale {
  return savedLocale() ?? deviceLocale();
}

export function saveLocale(locale: Locale): void {
  storage.set(LOCALE_KEY, locale);
}

/**
 * Points the layout direction at the language. Native layout direction only changes after a
 * reload, so this returns true when the caller should reload the app. A stored marker stops
 * a reload loop if the platform refuses the change.
 */
export function applyLayoutDirection(locale: Locale): boolean {
  const rtl = isRtl(locale);

  if (Platform.OS === 'web') {
    const root = globalThis.document?.documentElement;
    if (root) {
      root.dir = rtl ? 'rtl' : 'ltr';
      root.lang = locale;
    }
    return false;
  }

  I18nManager.allowRTL(rtl);
  I18nManager.forceRTL(rtl);

  if (I18nManager.isRTL === rtl) {
    void storage.remove(RELOADED_FOR_KEY);
    return false;
  }
  if (storage.get(RELOADED_FOR_KEY) === locale) {
    return false;
  }
  storage.set(RELOADED_FOR_KEY, locale);
  return true;
}
