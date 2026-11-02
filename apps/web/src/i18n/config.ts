// Arabic first (right to left), English second. The URL always carries the
// locale (/ar/..., /en/...), so pages can be cached and shared per language.
export const locales = ["ar", "en"] as const;
export type Locale = (typeof locales)[number];
export const defaultLocale: Locale = "ar";

// Remembers the visitor's last language for URLs without a locale.
export const LOCALE_COOKIE = "gb_locale";

export function isLocale(value: string | undefined | null): value is Locale {
  return value === "ar" || value === "en";
}

export function dirOf(locale: Locale): "rtl" | "ltr" {
  return locale === "ar" ? "rtl" : "ltr";
}

export function otherLocale(locale: Locale): Locale {
  return locale === "ar" ? "en" : "ar";
}

// Intl tags. Saudi customers read Western digits on prices and phone numbers,
// and the Gregorian calendar for deadlines, so both are pinned here rather
// than left to ar-SA's defaults (Arabic-Indic digits, Umm al-Qura calendar).
// Switch nu-latn to nu-arab here to show Arabic-Indic digits everywhere.
export const intlTag: Record<Locale, string> = {
  ar: "ar-SA-u-nu-latn-ca-gregory",
  en: "en-SA-u-nu-latn-ca-gregory",
};

export const TIME_ZONE = "Asia/Riyadh";
