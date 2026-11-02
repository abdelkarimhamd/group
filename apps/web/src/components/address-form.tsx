"use client";

import { useId, useState, useTransition } from "react";
import { saveAddress } from "@/actions/account";
import { useI18n } from "@/i18n/client";
import { digitsOnly, formatSaudiMobile, normalizeSaudiMobile, toAsciiDigits } from "@/lib/phone";
import type { Address, AddressInput } from "@/lib/api/types";
import { Alert, Button, Field, Input, Spinner } from "./ui";

type TextField =
  | "recipient_name"
  | "recipient_phone"
  | "city"
  | "district"
  | "street"
  | "building_no"
  | "postal_code"
  | "additional_no"
  | "short_address";

const REQUIRED: TextField[] = ["recipient_name", "recipient_phone", "city", "district", "street", "building_no", "postal_code"];

type Values = Record<TextField, string>;

function initialValues(address: Address | undefined, defaults: { name?: string | null; phone?: string }): Values {
  return {
    recipient_name: address?.recipient_name ?? defaults.name ?? "",
    recipient_phone: address ? formatSaudiMobile(address.recipient_phone) : defaults.phone ? formatSaudiMobile(defaults.phone) : "",
    city: address?.city ?? "",
    district: address?.district ?? "",
    street: address?.street ?? "",
    building_no: address?.building_no ?? "",
    postal_code: address?.postal_code ?? "",
    additional_no: address?.additional_no ?? "",
    short_address: address?.short_address ?? "",
  };
}

/**
 * Adds or edits a Saudi National Address. Editing sends every field, because
 * the API replaces the whole address (optional fields left out are cleared).
 */
export function AddressForm({
  address,
  defaults = {},
  makeDefaultInitially = false,
  onSaved,
  onCancel,
}: {
  address?: Address;
  defaults?: { name?: string | null; phone?: string };
  makeDefaultInitially?: boolean;
  onSaved: (address: Address) => void;
  onCancel?: () => void;
}) {
  const { t, locale } = useI18n();
  const formId = useId();
  const [values, setValues] = useState<Values>(() => initialValues(address, defaults));
  const [isDefault, setIsDefault] = useState(address?.is_default ?? makeDefaultInitially);
  const [errors, setErrors] = useState<Partial<Record<TextField, string>>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const set = (field: TextField, value: string) => setValues((current) => ({ ...current, [field]: value }));

  function validate(): { input: AddressInput } | { errors: Partial<Record<TextField, string>> } {
    const found: Partial<Record<TextField, string>> = {};
    const trimmed = Object.fromEntries(Object.entries(values).map(([key, value]) => [key, value.trim()])) as Values;
    for (const field of REQUIRED) {
      if (!trimmed[field]) found[field] = t.addresses.invalid.required;
    }
    const phone = normalizeSaudiMobile(trimmed.recipient_phone);
    if (trimmed.recipient_phone && !phone) found.recipient_phone = t.auth.phoneInvalid;
    const building = toAsciiDigits(trimmed.building_no);
    if (building && !/^\d{4}$/.test(building)) found.building_no = t.addresses.invalid.building_no;
    const postal = toAsciiDigits(trimmed.postal_code);
    if (postal && !/^\d{5}$/.test(postal)) found.postal_code = t.addresses.invalid.postal_code;
    const additional = toAsciiDigits(trimmed.additional_no);
    if (additional && !/^\d{4}$/.test(additional)) found.additional_no = t.addresses.invalid.additional_no;
    const short = toAsciiDigits(trimmed.short_address).toUpperCase().replace(/\s+/g, "");
    if (short && !/^[A-Z]{4}\d{4}$/.test(short)) found.short_address = t.addresses.invalid.short_address;
    if (Object.keys(found).length > 0) return { errors: found };
    return {
      input: {
        recipient_name: trimmed.recipient_name,
        recipient_phone: phone!,
        city: trimmed.city,
        district: trimmed.district,
        street: trimmed.street,
        building_no: building,
        postal_code: postal,
        additional_no: additional || null,
        short_address: short || null,
        // Kept as they were: the form has no map yet.
        lat: address?.lat ?? null,
        lng: address?.lng ?? null,
        is_default: isDefault,
      },
    };
  }

  function submit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);
    const checked = validate();
    if ("errors" in checked) {
      setErrors(checked.errors);
      const first = Object.keys(checked.errors)[0];
      if (first) document.getElementById(`${formId}-${first}`)?.focus();
      return;
    }
    setErrors({});
    startTransition(async () => {
      const result = await saveAddress({ locale, id: address?.id, address: checked.input });
      if (result.ok) {
        onSaved(result.address);
        return;
      }
      if (result.status === 422 && result.fieldErrors) {
        const fieldErrors: Partial<Record<TextField, string>> = {};
        for (const field of Object.keys(result.fieldErrors)) {
          if (field === "recipient_phone") fieldErrors.recipient_phone = t.auth.phoneInvalid;
          else if (field in t.addresses.invalid) {
            fieldErrors[field as TextField] = t.addresses.invalid[field as keyof typeof t.addresses.invalid];
          } else if (field in values) {
            fieldErrors[field as TextField] = result.fieldErrors[field]?.[0] ?? t.addresses.invalid.required;
          }
        }
        setErrors(fieldErrors);
        if (Object.keys(fieldErrors).length > 0) return;
      }
      setFormError(result.status === 0 ? t.common.networkError : t.common.genericError);
    });
  }

  const field = (
    name: TextField,
    props: Partial<React.ComponentProps<"input">> & { optional?: boolean; hint?: string } = {},
  ) => {
    const { optional, hint, ...inputProps } = props;
    const id = `${formId}-${name}`;
    return (
      <Field
        label={t.addresses.fields[name]}
        htmlFor={id}
        error={errors[name]}
        hint={hint}
        optionalLabel={optional ? t.common.optional : undefined}
      >
        <Input
          id={id}
          name={name}
          value={values[name]}
          onChange={(event) => set(name, event.target.value)}
          aria-invalid={errors[name] ? true : undefined}
          aria-describedby={errors[name] ? `${id}-error` : hint ? `${id}-hint` : undefined}
          disabled={pending}
          {...inputProps}
        />
      </Field>
    );
  };

  return (
    <form onSubmit={submit} noValidate className="space-y-4" data-testid="address-form">
      <div className="grid gap-4 sm:grid-cols-2">
        {field("recipient_name", { autoComplete: "name", maxLength: 100 })}
        {field("recipient_phone", {
          autoComplete: "tel-national",
          inputMode: "tel",
          dir: "ltr",
          placeholder: "05x xxx xxxx",
          onChange: (event) => set("recipient_phone", event.target.value.slice(0, 16)),
        })}
        {field("city", { autoComplete: "address-level2", list: `${formId}-cities`, maxLength: 100 })}
        {field("district", { autoComplete: "address-level3", maxLength: 100 })}
        <div className="sm:col-span-2">{field("street", { autoComplete: "address-line1", maxLength: 150 })}</div>
        {field("building_no", {
          inputMode: "numeric",
          dir: "ltr",
          hint: t.addresses.hints.building_no,
          onChange: (event) => set("building_no", digitsOnly(event.target.value, 4)),
        })}
        {field("postal_code", {
          autoComplete: "postal-code",
          inputMode: "numeric",
          dir: "ltr",
          hint: t.addresses.hints.postal_code,
          onChange: (event) => set("postal_code", digitsOnly(event.target.value, 5)),
        })}
        {field("additional_no", {
          optional: true,
          inputMode: "numeric",
          dir: "ltr",
          hint: t.addresses.hints.additional_no,
          onChange: (event) => set("additional_no", digitsOnly(event.target.value, 4)),
        })}
        {field("short_address", {
          optional: true,
          dir: "ltr",
          autoCapitalize: "characters",
          hint: t.addresses.hints.short_address,
          onChange: (event) => set("short_address", event.target.value.toUpperCase().slice(0, 9)),
        })}
      </div>
      <datalist id={`${formId}-cities`}>
        {t.addresses.cities.map((city) => (
          <option key={city} value={city} />
        ))}
      </datalist>

      <label className="flex items-center gap-3 text-sm text-slate-800">
        <input
          type="checkbox"
          checked={isDefault}
          // The default only moves to another address; it is never switched off.
          disabled={pending || address?.is_default === true}
          onChange={(event) => setIsDefault(event.target.checked)}
          className="size-5 rounded border-slate-300 text-brand-700 focus:ring-brand-600"
        />
        {t.addresses.makeDefault}
      </label>

      {formError ? <Alert tone="danger">{formError}</Alert> : null}

      <div className="flex flex-wrap gap-3">
        <Button type="submit" disabled={pending}>
          {pending ? <Spinner /> : null}
          {pending ? t.common.saving : t.common.save}
        </Button>
        {onCancel ? (
          <Button type="button" variant="ghost" onClick={onCancel} disabled={pending}>
            {t.common.cancel}
          </Button>
        ) : null}
      </div>
    </form>
  );
}
