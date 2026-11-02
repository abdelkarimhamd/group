import { formatDate, formatDateTime, formatTime, isPast, remainingUntil } from '../time';

describe('Riyadh time formatting', () => {
  // 21:30 UTC is 00:30 the next day in Riyadh (UTC+3, no daylight saving).
  const lateUtc = '2026-11-07T21:30:00Z';

  it('shifts to Riyadh before taking the date', () => {
    expect(formatDate(lateUtc, 'en')).toBe('8 Nov 2026');
    expect(formatDate(lateUtc, 'ar')).toBe('8 نوفمبر 2026');
  });

  it('uses a 12-hour clock with localized suffixes', () => {
    expect(formatTime(lateUtc, 'en')).toBe('12:30 AM');
    expect(formatTime('2026-11-08T09:05:00Z', 'en')).toBe('12:05 PM');
    expect(formatTime('2026-11-08T10:00:00Z', 'ar')).toBe('1:00 م');
    expect(formatTime('2026-11-08T04:00:00Z', 'ar')).toBe('7:00 ص');
  });

  it('joins date and time with the right comma', () => {
    expect(formatDateTime(lateUtc, 'en')).toBe('8 Nov 2026, 12:30 AM');
    expect(formatDateTime(lateUtc, 'ar')).toBe('8 نوفمبر 2026، 12:30 ص');
  });
});

describe('remainingUntil', () => {
  const now = Date.parse('2026-11-02T10:00:00Z');

  it('breaks the gap into parts', () => {
    expect(remainingUntil('2026-11-04T15:12:09Z', now)).toEqual({
      totalMs: (2 * 86_400 + 5 * 3_600 + 12 * 60 + 9) * 1000,
      days: 2,
      hours: 5,
      minutes: 12,
      seconds: 9,
    });
  });

  it('floors at zero once the deadline passed', () => {
    expect(remainingUntil('2026-11-01T10:00:00Z', now).totalMs).toBe(0);
    expect(isPast('2026-11-02T10:00:00Z', now)).toBe(true);
    expect(isPast('2026-11-02T10:00:01Z', now)).toBe(false);
  });
});
