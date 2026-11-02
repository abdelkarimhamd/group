import { normalizeDigits } from './money';

/** A Saudi mobile in the E.164 form the API stores: +9665 and eight digits. */
export const SAUDI_MOBILE_PATTERN = /^\+9665[0-9]{8}$/;

/**
 * Accepts the ways people type a Saudi mobile ("05x xxx xxxx", "5xxxxxxxx",
 * "9665…", "009665…", "+9665…", Arabic-Indic digits) and returns the E.164
 * form, or null when it is not a Saudi mobile.
 */
export function normalizeSaudiMobile(input: string): string | null {
  const digits = normalizeDigits(input).replace(/[\s\-().]/g, '');
  let national: string;
  if (digits.startsWith('+966')) national = digits.slice(4);
  else if (digits.startsWith('00966')) national = digits.slice(5);
  else if (digits.startsWith('966')) national = digits.slice(3);
  else if (digits.startsWith('0')) national = digits.slice(1);
  else national = digits;
  const e164 = `+966${national}`;
  return SAUDI_MOBILE_PATTERN.test(e164) ? e164 : null;
}

/** "+966512345678" shown as "+966 51 234 5678". */
export function formatSaudiMobile(e164: string | null | undefined): string {
  if (!e164) return '—';
  const m = /^\+966(5\d)(\d{3})(\d{4})$/.exec(e164);
  return m ? `+966 ${m[1]} ${m[2]} ${m[3]}` : e164;
}
