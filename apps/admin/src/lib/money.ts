import type { Money } from '../api/types';

/**
 * Money in the portal is always an integer number of minor units (halalas for
 * SAR), exactly as the API sends it. Amounts typed by an admin are parsed from
 * text without ever passing through a float, and shown without division.
 */

/** Every currency the contract allows (SAR, USD, CNY) has two minor digits. */
const MINOR_DIGITS = 2;
const MINOR_FACTOR = 10n ** BigInt(MINOR_DIGITS);

const ARABIC_INDIC = /[٠-٩۰-۹]/g;

/** Turns Arabic-Indic and Persian digits into ASCII digits. */
export function normalizeDigits(input: string): string {
  return input.replace(ARABIC_INDIC, (ch) => {
    const code = ch.charCodeAt(0);
    return String(code >= 0x06f0 ? code - 0x06f0 : code - 0x0660);
  });
}

/**
 * Parses an amount typed in major units ("69", "69.5", "1,234.50") into
 * minor units. Returns null for anything that is not a plain non-negative
 * amount with at most two decimals.
 */
export function parseMajorToMinor(input: string): number | null {
  const text = normalizeDigits(input)
    .trim()
    .replace(/[,٬\s]/g, '')
    .replace('٫', '.');
  const match = /^(\d{1,13})(?:\.(\d{0,2}))?$/.exec(text);
  if (!match) return null;
  const major = BigInt(match[1] ?? '0');
  const minor = BigInt((match[2] ?? '').padEnd(MINOR_DIGITS, '0') || '0');
  const value = major * MINOR_FACTOR + minor;
  if (value > BigInt(Number.MAX_SAFE_INTEGER)) return null;
  return Number(value);
}

/** Minor units to the text an input shows, for example 6900 to "69.00". */
export function minorToMajorText(amountMinor: number): string {
  assertInteger(amountMinor);
  const negative = amountMinor < 0;
  const abs = BigInt(Math.abs(amountMinor));
  const major = abs / MINOR_FACTOR;
  const minor = (abs % MINOR_FACTOR).toString().padStart(MINOR_DIGITS, '0');
  return `${negative ? '-' : ''}${major.toString()}.${minor}`;
}

/** Display text with thousands separators and the currency code: "1,234.50 SAR". */
export function formatMinor(amountMinor: number, currency: string): string {
  assertInteger(amountMinor);
  const negative = amountMinor < 0;
  const abs = BigInt(Math.abs(amountMinor));
  const major = new Intl.NumberFormat('en-US').format(abs / MINOR_FACTOR);
  const minor = (abs % MINOR_FACTOR).toString().padStart(MINOR_DIGITS, '0');
  return `${negative ? '-' : ''}${major}.${minor} ${currency}`;
}

export function formatMoney(money: Money | null | undefined): string {
  if (!money) return '—';
  return formatMinor(money.amount_minor, money.currency);
}

/** Integer sum that refuses to leave the safe integer range. */
export function sumMinor(values: readonly number[]): number {
  const total = values.reduce((acc, v) => {
    assertInteger(v);
    return acc + BigInt(v);
  }, 0n);
  if (total > BigInt(Number.MAX_SAFE_INTEGER) || total < BigInt(Number.MIN_SAFE_INTEGER)) {
    throw new RangeError('Amount out of range');
  }
  return Number(total);
}

/** Basis points as a percentage string: 1500 to "15.00%". */
export function formatBasisPoints(bp: number): string {
  assertInteger(bp);
  const negative = bp < 0;
  const abs = Math.abs(bp);
  return `${negative ? '-' : ''}${Math.trunc(abs / 100)}.${String(abs % 100).padStart(2, '0')}%`;
}

/** A decimal string such as an FX rate: digits, optionally a dot and up to 8 decimals. */
export const DECIMAL_RATE_PATTERN = /^[0-9]{1,10}(\.[0-9]{1,8})?$/;

function assertInteger(value: number): void {
  if (!Number.isSafeInteger(value)) {
    throw new TypeError(`Money must be a safe integer of minor units, got ${value}`);
  }
}
