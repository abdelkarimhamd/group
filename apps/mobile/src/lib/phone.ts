const DIGIT_MAP: Record<string, string> = {};
'٠١٢٣٤٥٦٧٨٩'.split('').forEach((d, i) => (DIGIT_MAP[d] = String(i)));
'۰۱۲۳۴۵۶۷۸۹'.split('').forEach((d, i) => (DIGIT_MAP[d] = String(i)));

/** Converts Arabic-Indic and Persian digits to ASCII so typed codes and numbers validate. */
export function toAsciiDigits(input: string): string {
  return input.replace(/[٠-٩۰-۹]/g, (d) => DIGIT_MAP[d] ?? d);
}

/**
 * Normalizes what a customer types into the E.164 Saudi mobile the API expects
 * (`^\+9665[0-9]{8}$`). Accepts 05XXXXXXXX, 5XXXXXXXX, +9665..., 009665... with spaces
 * or dashes, in Western or Arabic digits. Returns null when it is not a Saudi mobile.
 */
export function normalizeSaudiMobile(input: string): string | null {
  let digits = toAsciiDigits(input).replace(/[\s\-().]/g, '');
  if (digits.startsWith('+')) digits = digits.slice(1);
  else if (digits.startsWith('00')) digits = digits.slice(2);
  if (digits.startsWith('966')) digits = digits.slice(3);
  if (digits.startsWith('0')) digits = digits.slice(1);
  return /^5\d{8}$/.test(digits) ? `+966${digits}` : null;
}

/**
 * "+966512345678" -> "+966 51 234 5678". By default the number is wrapped in a left-to-right
 * isolate so it keeps its order inside Arabic sentences; pass `isolate: false` for inputs.
 */
export function formatSaudiMobile(e164: string, options: { isolate?: boolean } = {}): string {
  const match = /^\+966(5\d)(\d{3})(\d{4})$/.exec(e164);
  const text = match ? `+966 ${match[1]} ${match[2]} ${match[3]}` : e164;
  return options.isolate === false ? text : `\u2066${text}\u2069`;
}

/** Keeps only up to `length` ASCII digits, for code and number fields. */
export function digitsOnly(input: string, length?: number): string {
  const digits = toAsciiDigits(input).replace(/\D/g, '');
  return length === undefined ? digits : digits.slice(0, length);
}
