import type { Locale } from './locale';

export type PluralCategory = 'zero' | 'one' | 'two' | 'few' | 'many' | 'other';

/**
 * CLDR plural categories for whole numbers. Hermes has no Intl.PluralRules, so the two rules
 * this app needs are written out here.
 */
export function pluralCategory(locale: Locale, n: number): PluralCategory {
  const count = Math.abs(Math.trunc(n));
  if (locale === 'ar') {
    if (count === 0) return 'zero';
    if (count === 1) return 'one';
    if (count === 2) return 'two';
    const lastTwo = count % 100;
    if (lastTwo >= 3 && lastTwo <= 10) return 'few';
    if (lastTwo >= 11 && lastTwo <= 99) return 'many';
    return 'other';
  }
  return count === 1 ? 'one' : 'other';
}
