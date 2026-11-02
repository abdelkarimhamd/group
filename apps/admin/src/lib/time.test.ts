import { describe, expect, it } from 'vitest';
import {
  dayjs,
  formatDate,
  formatDateTime,
  hoursUntil,
  riyadhPickerValueToUtc,
  toRiyadhPickerValue,
} from './time';

describe('Riyadh time', () => {
  it('shows UTC instants in Riyadh time (UTC+3)', () => {
    expect(formatDateTime('2026-11-02T21:30:00Z')).toBe('2026-11-03 00:30');
    expect(formatDate('2026-11-02T22:00:00Z')).toBe('2026-11-03');
    expect(formatDate('2026-11-02')).toBe('2026-11-02');
    expect(formatDateTime(null)).toBe('—');
  });

  it('round-trips a picker value whatever the browser zone', () => {
    const picked = toRiyadhPickerValue('2026-11-10T09:00:00Z');
    expect(picked?.format('YYYY-MM-DD HH:mm')).toBe('2026-11-10 12:00');
    expect(riyadhPickerValueToUtc(picked!)).toBe('2026-11-10T09:00:00Z');
  });

  it('reads typed Riyadh wall-clock time as UTC+3', () => {
    expect(riyadhPickerValueToUtc(dayjs('2026-11-10T00:15:00'))).toBe('2026-11-09T21:15:00Z');
  });

  it('counts whole hours until an instant', () => {
    const now = new Date('2026-11-02T10:00:00Z');
    expect(hoursUntil('2026-11-02T13:59:00Z', now)).toBe(3);
    expect(hoursUntil('2026-11-02T09:00:00Z', now)).toBe(-1);
  });
});
