import { DatePicker, Input, type FormInstance, type FormRule } from 'antd';
import type { TFunction } from 'i18next';
import type { Dayjs } from 'dayjs';
import { useTranslation } from 'react-i18next';
import { toApiError } from '../api/errors';
import { DECIMAL_RATE_PATTERN, parseMajorToMinor } from '../lib/money';
import { PASSWORD_MAX_LENGTH, PASSWORD_MIN_LENGTH } from '../lib/password';
import { normalizeSaudiMobile } from '../lib/phone';

/**
 * Money is typed in major units ("69.50") and kept as text in the form. It is
 * converted to integer minor units with parseMajorToMinor on submit.
 */
export function MoneyInput({
  currency = 'SAR',
  ...props
}: { currency?: string } & React.ComponentProps<typeof Input>) {
  return (
    <Input
      inputMode="decimal"
      dir="ltr"
      addonAfter={currency}
      placeholder="0.00"
      autoComplete="off"
      {...props}
    />
  );
}

export function moneyRules(
  t: TFunction,
  { required = true, min = 0 }: { required?: boolean; min?: number } = {},
): FormRule[] {
  return [
    { required, message: t('validation.required') },
    {
      validator: async (_, value: string | undefined) => {
        if (value === undefined || value === '') return;
        const minor = parseMajorToMinor(value);
        if (minor === null) throw new Error(t('validation.money'));
        if (minor < min) throw new Error(t('validation.moneyMin'));
      },
    },
  ];
}

/** Reads a money field that passed moneyRules. */
export function minorOf(value: string | undefined): number {
  const minor = value === undefined || value === '' ? null : parseMajorToMinor(value);
  if (minor === null) throw new Error('Money field was not validated');
  return minor;
}

export function optionalMinorOf(value: string | undefined): number | null {
  return value === undefined || value === '' ? null : minorOf(value);
}

export function rateRules(t: TFunction, required = true): FormRule[] {
  return [
    { required, message: t('validation.required') },
    { pattern: DECIMAL_RATE_PATTERN, message: t('validation.rate') },
  ];
}

export function intRules(
  t: TFunction,
  { required = true, min }: { required?: boolean; min?: number } = {},
): FormRule[] {
  const rules: FormRule[] = [{ required, message: t('validation.required') }];
  if (min !== undefined) {
    rules.push({ type: 'number', min, message: t('validation.min', { n: min }) });
  }
  return rules;
}

export function requiredRule(t: TFunction): FormRule {
  return { required: true, whitespace: true, message: t('validation.required') };
}

/** A date-time picker whose value is Riyadh wall-clock time (see lib/time). */
export function RiyadhDateTimePicker(props: {
  value?: Dayjs;
  onChange?: (value: Dayjs | null) => void;
  disabledDate?: (d: Dayjs) => boolean;
  id?: string;
}) {
  const { t } = useTranslation();
  return (
    <DatePicker
      showTime={{ format: 'HH:mm' }}
      format="YYYY-MM-DD HH:mm"
      style={{ width: '100%' }}
      suffixIcon={<span style={{ fontSize: 12 }}>{t('common.riyadhTime')}</span>}
      {...props}
    />
  );
}

/**
 * Puts the API's 422 field errors on the matching form fields. Dotted names
 * like "tiers.0.max_qty" become ['tiers', 0, 'max_qty']. Returns true when at
 * least one field matched, so the caller can skip a general error.
 */
export function applyFieldErrors(form: FormInstance, error: unknown): boolean {
  const apiError = toApiError(error);
  const entries = Object.entries(apiError.fieldErrors);
  if (entries.length === 0) return false;
  const known = entries.filter(([name]) => {
    const path = toNamePath(name);
    try {
      return form.getFieldInstance(path) !== undefined || form.getFieldValue(path) !== undefined;
    } catch {
      return false;
    }
  });
  form.setFields(known.map(([name, errors]) => ({ name: toNamePath(name), errors })));
  return known.length === entries.length;
}

function toNamePath(name: string): (string | number)[] {
  return name.split('.').map((part) => (/^\d+$/.test(part) ? Number(part) : part));
}

/** A Saudi mobile, typed any common way; read it back with normalizeSaudiMobile. */
export function phoneRules(t: TFunction, required = true): FormRule[] {
  return [
    { required, message: t('validation.required') },
    {
      validator: async (_, value: string | undefined) => {
        if (!value) return;
        if (normalizeSaudiMobile(value) === null) throw new Error(t('validation.phone'));
      },
    },
  ];
}

export function passwordRules(t: TFunction, required = true): FormRule[] {
  return [
    { required, message: t('validation.required') },
    { min: PASSWORD_MIN_LENGTH, message: t('validation.minLength', { n: PASSWORD_MIN_LENGTH }) },
    { max: PASSWORD_MAX_LENGTH, message: t('validation.maxLength', { n: PASSWORD_MAX_LENGTH }) },
  ];
}
