import type { Locale } from '@/i18n/locale';

/** The API's money object: integer minor units (halalas for SAR). */
export type Money = {
  amount_minor: number;
  currency: 'SAR' | 'USD' | 'CNY';
  formatted: string;
};

const CURRENCY_LABELS: Record<Locale, Record<string, string>> = {
  ar: { SAR: 'ر.س', USD: 'دولار', CNY: 'يوان' },
  en: { SAR: 'SAR', USD: 'USD', CNY: 'CNY' },
};

function assertMinor(amountMinor: number): void {
  if (!Number.isSafeInteger(amountMinor)) {
    throw new RangeError(`Money must be a safe integer of minor units, got ${amountMinor}`);
  }
}

function groupThousands(digits: string): string {
  return digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

/**
 * Formats integer minor units without floating point: 13800 -> "138.00 SAR" / "138.00 ر.س".
 * Western digits are used in both languages, as most Saudi shopping apps do.
 * `compact` drops ".00" for whole amounts, for price tags.
 */
export function formatMinor(
  amountMinor: number,
  currency: string,
  locale: Locale,
  options: { compact?: boolean } = {},
): string {
  assertMinor(amountMinor);
  const abs = Math.abs(amountMinor);
  const fraction = abs % 100;
  const whole = (abs - fraction) / 100;
  let text = groupThousands(String(whole));
  if (!(options.compact && fraction === 0)) {
    text += `.${String(fraction).padStart(2, '0')}`;
  }
  const sign = amountMinor < 0 ? '-' : '';
  const label = CURRENCY_LABELS[locale][currency] ?? currency;
  // Isolate the number so a minus sign or grouping never reorders inside Arabic text.
  return `\u2066${sign}${text}\u2069 ${label}`;
}

export function formatMoney(money: Money, locale: Locale, options?: { compact?: boolean }): string {
  return formatMinor(money.amount_minor, money.currency, locale, options);
}

/** Unit price x quantity for display. The server computes every amount that is charged. */
export function multiplyMinor(unitMinor: number, quantity: number): number {
  assertMinor(unitMinor);
  if (!Number.isSafeInteger(quantity) || quantity < 0) {
    throw new RangeError(`Quantity must be a non-negative integer, got ${quantity}`);
  }
  const total = unitMinor * quantity;
  assertMinor(total);
  return total;
}

/** Saving per unit between two prices, never negative. */
export function savingMinor(fromMinor: number, toMinor: number): number {
  assertMinor(fromMinor);
  assertMinor(toMinor);
  return Math.max(0, fromMinor - toMinor);
}
