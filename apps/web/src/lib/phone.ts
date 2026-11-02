// Saudi mobile numbers: customers type them many ways (05x..., 5x..., +9665...,
// 009665..., with spaces or Arabic-Indic digits). The API wants E.164.

const ARABIC_INDIC = "٠١٢٣٤٥٦٧٨٩";
const EXTENDED_ARABIC_INDIC = "۰۱۲۳۴۵۶۷۸۹";

/** Turns Arabic-Indic and Persian digits into ASCII digits. */
export function toAsciiDigits(value: string): string {
  return value.replace(/[٠-٩۰-۹]/g, (digit) => {
    const index = ARABIC_INDIC.indexOf(digit);
    return String(index >= 0 ? index : EXTENDED_ARABIC_INDIC.indexOf(digit));
  });
}

/** "+9665XXXXXXXX", or null when the input is not a Saudi mobile number. */
export function normalizeSaudiMobile(input: string): string | null {
  let digits = toAsciiDigits(input).replace(/[\s\-().]/g, "");
  if (digits.startsWith("+")) digits = digits.slice(1);
  else if (digits.startsWith("00")) digits = digits.slice(2);

  let national: string;
  if (/^9665\d{8}$/.test(digits)) national = digits.slice(3);
  else if (/^05\d{8}$/.test(digits)) national = digits.slice(1);
  else if (/^5\d{8}$/.test(digits)) national = digits;
  else return null;

  return `+966${national}`;
}

/** "+966512345678" shown the way Saudis write it: "051 234 5678". */
export function formatSaudiMobile(e164: string): string {
  const match = /^\+966(5\d)(\d{3})(\d{4})$/.exec(e164);
  if (!match) return e164;
  return `0${match[1]} ${match[2]} ${match[3]}`;
}

/** Keeps only ASCII digits, for code inputs. */
export function digitsOnly(value: string, maxLength: number): string {
  return toAsciiDigits(value).replace(/\D/g, "").slice(0, maxLength);
}
