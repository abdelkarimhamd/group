import { useCallback, useMemo } from 'react';
import { useTranslation } from 'react-i18next';

import { formatMinor, formatMoney, type Money } from '@/lib/money';
import { formatSaudiMobile } from '@/lib/phone';
import { formatDate, formatDateTime, remainingUntil, type Remaining } from '@/lib/time';

import { isLocale, isRtl, type Locale } from './locale';
import { pluralCategory } from './plural';

type Vars = Record<string, string | number>;

/** Translation plus locale-aware money, date and countdown formatting. */
export function useFormat() {
  const { t, i18n } = useTranslation();
  const locale: Locale = isLocale(i18n.language) ? i18n.language : 'ar';

  /** Plural lookup: `tp('progress.moreNeeded', 47)` reads `progress.moreNeeded_many` in Arabic. */
  const tp = useCallback(
    (key: string, n: number, vars: Vars = {}): string => {
      const category = pluralCategory(locale, n);
      const exact = `${key}_${category}`;
      const resolved = i18n.exists(exact) ? exact : `${key}_other`;
      return t(resolved, { ...vars, n });
    },
    [i18n, locale, t],
  );

  /** "2 days and 5 hours", "5 hours and 12 minutes", "12 minutes", "less than a minute". */
  const duration = useCallback(
    (r: Remaining): string => {
      if (r.days > 0) {
        if (r.hours === 0) return tp('common.days', r.days);
        return t('common.daysAndHours', {
          days: tp('common.days', r.days),
          hours: tp('common.hours', r.hours),
        });
      }
      if (r.hours > 0) {
        if (r.minutes === 0) return tp('common.hours', r.hours);
        return t('common.hoursAndMinutes', {
          hours: tp('common.hours', r.hours),
          minutes: tp('common.minutes', r.minutes),
        });
      }
      if (r.minutes > 0) return tp('common.minutes', r.minutes);
      return t('common.lessThanAMinute');
    },
    [t, tp],
  );

  return useMemo(
    () => ({
      t,
      tp,
      locale,
      rtl: isRtl(locale),
      money: (value: Money, options?: { compact?: boolean }) => formatMoney(value, locale, options),
      minor: (amountMinor: number, currency = 'SAR', options?: { compact?: boolean }) =>
        formatMinor(amountMinor, currency, locale, options),
      date: (value: string) => formatDate(value, locale),
      dateTime: (value: string) => formatDateTime(value, locale),
      timeLeft: (until: string, now?: number) => duration(remainingUntil(until, now)),
      /** A phone number on its own line; the leading mark keeps Arabic lines right-aligned. */
      phone: (e164: string) => `${isRtl(locale) ? '\u200F' : ''}${formatSaudiMobile(e164)}`,
      duration,
    }),
    [duration, locale, t, tp],
  );
}

/** "14:05" for a countdown under an hour, "1:02:03" above. */
export function clock(r: Remaining): string {
  const totalHours = r.days * 24 + r.hours;
  const mm = String(r.minutes).padStart(2, '0');
  const ss = String(r.seconds).padStart(2, '0');
  return totalHours > 0 ? `${totalHours}:${mm}:${ss}` : `${r.minutes}:${ss}`;
}
