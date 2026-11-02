import dayjs, { type Dayjs } from 'dayjs';
import timezone from 'dayjs/plugin/timezone';
import utc from 'dayjs/plugin/utc';
import { DISPLAY_TIMEZONE } from '../config';

dayjs.extend(utc);
dayjs.extend(timezone);

/**
 * The API speaks UTC. People read and type Riyadh time. Date pickers work in
 * the browser's own zone, so a picker value carries Riyadh wall-clock time and
 * is converted only at the edges.
 */

export function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return '—';
  return dayjs.utc(iso).tz(DISPLAY_TIMEZONE).format('YYYY-MM-DD HH:mm');
}

export function formatDate(date: string | null | undefined): string {
  if (!date) return '—';
  return date.length === 10 ? date : dayjs.utc(date).tz(DISPLAY_TIMEZONE).format('YYYY-MM-DD');
}

/** A UTC instant from the API to a picker value showing Riyadh wall-clock time. */
export function toRiyadhPickerValue(iso: string | null | undefined): Dayjs | undefined {
  if (!iso) return undefined;
  return dayjs(dayjs.utc(iso).tz(DISPLAY_TIMEZONE).format('YYYY-MM-DDTHH:mm:ss'));
}

/** A picker value holding Riyadh wall-clock time to a UTC instant for the API. */
export function riyadhPickerValueToUtc(value: Dayjs): string {
  return dayjs
    .tz(value.format('YYYY-MM-DDTHH:mm:ss'), DISPLAY_TIMEZONE)
    .utc()
    .format('YYYY-MM-DDTHH:mm:ss[Z]');
}

/** Picker value to a calendar date string (YYYY-MM-DD). */
export function pickerValueToDate(value: Dayjs): string {
  return value.format('YYYY-MM-DD');
}

export function nowRiyadhPickerValue(): Dayjs {
  return dayjs(dayjs().tz(DISPLAY_TIMEZONE).format('YYYY-MM-DDTHH:mm:ss'));
}

/** Whole hours from now until the instant; negative when it has passed. */
export function hoursUntil(iso: string, now: Date = new Date()): number {
  return Math.floor((new Date(iso).getTime() - now.getTime()) / 3_600_000);
}

export { dayjs };
