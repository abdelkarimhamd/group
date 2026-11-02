import type { Locale } from '@/i18n/locale';

/**
 * The API sends UTC; customers see Riyadh time. Asia/Riyadh is UTC+3 all year (no daylight
 * saving), so a fixed offset keeps formatting identical on iOS, Android and in tests instead
 * of depending on each engine's time zone data.
 */
const RIYADH_OFFSET_MS = 3 * 60 * 60 * 1000;

const MONTHS: Record<Locale, string[]> = {
  en: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
  ar: [
    'يناير',
    'فبراير',
    'مارس',
    'أبريل',
    'مايو',
    'يونيو',
    'يوليو',
    'أغسطس',
    'سبتمبر',
    'أكتوبر',
    'نوفمبر',
    'ديسمبر',
  ],
};

function toDate(value: string | number | Date): Date {
  return value instanceof Date ? value : new Date(value);
}

function riyadh(value: string | number | Date) {
  const shifted = new Date(toDate(value).getTime() + RIYADH_OFFSET_MS);
  return {
    year: shifted.getUTCFullYear(),
    month: shifted.getUTCMonth(),
    day: shifted.getUTCDate(),
    hours: shifted.getUTCHours(),
    minutes: shifted.getUTCMinutes(),
  };
}

/** "8 Nov 2026" / "8 نوفمبر 2026" in Riyadh time. */
export function formatDate(value: string | number | Date, locale: Locale): string {
  const p = riyadh(value);
  return `${p.day} ${MONTHS[locale][p.month]} ${p.year}`;
}

/** "10:00 AM" / "10:00 ص" in Riyadh time. */
export function formatTime(value: string | number | Date, locale: Locale): string {
  const p = riyadh(value);
  const hour12 = p.hours % 12 === 0 ? 12 : p.hours % 12;
  const minutes = String(p.minutes).padStart(2, '0');
  const isAm = p.hours < 12;
  const suffix = locale === 'ar' ? (isAm ? 'ص' : 'م') : isAm ? 'AM' : 'PM';
  return `${hour12}:${minutes} ${suffix}`;
}

/** "8 Nov 2026, 10:00 AM" / "8 نوفمبر 2026، 10:00 ص" in Riyadh time. */
export function formatDateTime(value: string | number | Date, locale: Locale): string {
  const comma = locale === 'ar' ? '،' : ',';
  return `${formatDate(value, locale)}${comma} ${formatTime(value, locale)}`;
}

export type Remaining = {
  totalMs: number;
  days: number;
  hours: number;
  minutes: number;
  seconds: number;
};

/** Time left until `deadline`, floored at zero. */
export function remainingUntil(
  deadline: string | number | Date,
  now: number = Date.now(),
): Remaining {
  const totalMs = Math.max(0, toDate(deadline).getTime() - now);
  const totalSeconds = Math.floor(totalMs / 1000);
  return {
    totalMs,
    days: Math.floor(totalSeconds / 86_400),
    hours: Math.floor((totalSeconds % 86_400) / 3_600),
    minutes: Math.floor((totalSeconds % 3_600) / 60),
    seconds: totalSeconds % 60,
  };
}

export function isPast(value: string | number | Date, now: number = Date.now()): boolean {
  return toDate(value).getTime() <= now;
}
