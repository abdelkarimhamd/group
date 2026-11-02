import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';
import ar from './ar';
import en from './en';

export const LOCALES = ['ar', 'en'] as const;
export type Locale = (typeof LOCALES)[number];

const STORAGE_KEY = 'groupbuy.admin.locale';

/** Arabic first, like the API (groupbuy.locales). The choice is remembered per browser. */
export function storedLocale(): Locale {
  try {
    const value = globalThis.localStorage?.getItem(STORAGE_KEY);
    if (value === 'ar' || value === 'en') return value;
  } catch {
    // Storage blocked: use the default.
  }
  return 'ar';
}

export function rememberLocale(locale: Locale): void {
  try {
    globalThis.localStorage?.setItem(STORAGE_KEY, locale);
  } catch {
    // Not remembered; the session still switches.
  }
}

export function directionOf(locale: Locale): 'rtl' | 'ltr' {
  return locale === 'ar' ? 'rtl' : 'ltr';
}

void i18n.use(initReactI18next).init({
  resources: { ar: { translation: ar }, en: { translation: en } },
  lng: storedLocale(),
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
  returnNull: false,
});

export default i18n;
