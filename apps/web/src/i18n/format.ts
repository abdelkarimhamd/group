import { intlTag, TIME_ZONE, type Locale } from "./config";

// Formatting helpers shared by server and client components. Money arrives as
// integer minor units (halalas) and is never turned into a float: the decimal
// string is built from integer parts and handed to Intl as a string.

export type MoneyLike = { amount_minor: number; currency: string };

const currencyLabel: Record<Locale, Record<string, string>> = {
  ar: { SAR: "ر.س", USD: "دولار", CNY: "يوان" },
  en: { SAR: "SAR", USD: "USD", CNY: "CNY" },
};

export function minorToDecimalString(amountMinor: number): string {
  if (!Number.isSafeInteger(amountMinor)) {
    throw new RangeError(`Money must be a safe integer of minor units, got ${amountMinor}`);
  }
  const sign = amountMinor < 0 ? "-" : "";
  const abs = Math.abs(amountMinor);
  const minor = abs % 100;
  const major = (abs - minor) / 100;
  return `${sign}${major}.${String(minor).padStart(2, "0")}`;
}

/** "69 ر.س" / "SAR 69", with two decimals only when there are halalas. */
export function formatMoney(money: MoneyLike, locale: Locale): string {
  const hasFraction = money.amount_minor % 100 !== 0;
  const nf = new Intl.NumberFormat(intlTag[locale], {
    minimumFractionDigits: hasFraction ? 2 : 0,
    maximumFractionDigits: 2,
  });
  // Intl.NumberFormat formats decimal strings exactly (no float rounding).
  const number = nf.format(minorToDecimalString(money.amount_minor) as unknown as number);
  const label = currencyLabel[locale][money.currency] ?? money.currency;
  return locale === "ar" ? `${number} ${label}` : `${label} ${number}`;
}

export function sar(amountMinor: number): MoneyLike {
  return { amount_minor: amountMinor, currency: "SAR" };
}

/** Multiplies a unit price by a whole quantity, staying in integer minor units. */
export function multiplyMoney(money: MoneyLike, quantity: number): MoneyLike {
  if (!Number.isSafeInteger(quantity) || quantity < 0) {
    throw new RangeError(`Quantity must be a non-negative integer, got ${quantity}`);
  }
  const amount = money.amount_minor * quantity;
  if (!Number.isSafeInteger(amount)) throw new RangeError("Money overflow");
  return { amount_minor: amount, currency: money.currency };
}

export function formatNumber(value: number, locale: Locale): string {
  return new Intl.NumberFormat(intlTag[locale]).format(value);
}

const dateStyles = {
  date: { dateStyle: "medium" },
  dateTime: { dateStyle: "medium", timeStyle: "short" },
  long: { weekday: "long", day: "numeric", month: "long", hour: "numeric", minute: "2-digit" },
} satisfies Record<string, Intl.DateTimeFormatOptions>;

/** Times arrive in UTC and are always shown in Riyadh time. */
export function formatDate(
  iso: string,
  locale: Locale,
  style: keyof typeof dateStyles = "dateTime",
): string {
  return new Intl.DateTimeFormat(intlTag[locale], {
    ...dateStyles[style],
    timeZone: TIME_ZONE,
  }).format(new Date(iso));
}

export type PluralForms = Partial<Record<Intl.LDMLPluralRule, string>> & { other: string };

export function plural(forms: PluralForms, count: number, locale: Locale): string {
  const rule = new Intl.PluralRules(intlTag[locale]).select(count);
  const template = forms[rule] ?? forms.other;
  return template.replaceAll("{count}", formatNumber(count, locale));
}

/**
 * Wraps a code, number or Latin word in a left-to-right isolate so it keeps
 * its order inside an Arabic sentence ("حملة رقم GB-2611-0009").
 */
export function ltr(text: string): string {
  return `\u2066${text}\u2069`;
}

/** Replaces {name} placeholders. Unknown placeholders are left as they are. */
export function interpolate(template: string, values: Record<string, string | number>): string {
  return template.replace(/\{(\w+)\}/g, (match, key: string) =>
    key in values ? String(values[key]) : match,
  );
}

/** Splits a duration in milliseconds into whole days, hours, minutes and seconds. */
export function splitDuration(ms: number) {
  const total = Math.max(0, Math.floor(ms / 1000));
  return {
    days: Math.floor(total / 86400),
    hours: Math.floor((total % 86400) / 3600),
    minutes: Math.floor((total % 3600) / 60),
    seconds: total % 60,
  };
}
