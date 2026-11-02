import type { AddressInput } from '@/api/types';

import { digitsOnly, normalizeSaudiMobile } from './phone';

export type AddressForm = {
  recipient_name: string;
  recipient_phone: string;
  city: string;
  district: string;
  street: string;
  building_no: string;
  postal_code: string;
  additional_no: string;
  short_address: string;
  is_default: boolean;
};

export type AddressField = Exclude<keyof AddressForm, 'is_default'>;

/** Translation keys under `validation.` */
export type AddressError = 'required' | 'phone' | 'fourDigits' | 'fiveDigits' | 'shortAddress';

export const EMPTY_ADDRESS: AddressForm = {
  recipient_name: '',
  recipient_phone: '',
  city: '',
  district: '',
  street: '',
  building_no: '',
  postal_code: '',
  additional_no: '',
  short_address: '',
  is_default: false,
};

export function addressToForm(address: AddressInput): AddressForm {
  return {
    recipient_name: address.recipient_name,
    recipient_phone: address.recipient_phone.replace(/^\+966/, '0'),
    city: address.city,
    district: address.district,
    street: address.street,
    building_no: address.building_no,
    postal_code: address.postal_code,
    additional_no: address.additional_no ?? '',
    short_address: address.short_address ?? '',
    is_default: address.is_default ?? false,
  };
}

/**
 * Checks the Saudi National Address fields with the same rules as the contract's
 * AddressInput, and returns the request body when everything is valid.
 */
export function validateAddress(form: AddressForm): {
  input: AddressInput | null;
  errors: Partial<Record<AddressField, AddressError>>;
} {
  const errors: Partial<Record<AddressField, AddressError>> = {};
  const text = (field: 'recipient_name' | 'city' | 'district' | 'street') => {
    const value = form[field].trim();
    if (!value) errors[field] = 'required';
    return value;
  };

  const recipientName = text('recipient_name');
  const city = text('city');
  const district = text('district');
  const street = text('street');

  const phone = normalizeSaudiMobile(form.recipient_phone);
  if (!phone) errors.recipient_phone = form.recipient_phone.trim() ? 'phone' : 'required';

  const buildingNo = digitsOnly(form.building_no);
  if (!/^\d{4}$/.test(buildingNo)) errors.building_no = buildingNo ? 'fourDigits' : 'required';

  const postalCode = digitsOnly(form.postal_code);
  if (!/^\d{5}$/.test(postalCode)) errors.postal_code = postalCode ? 'fiveDigits' : 'required';

  const additionalNo = digitsOnly(form.additional_no);
  if (additionalNo && !/^\d{4}$/.test(additionalNo)) errors.additional_no = 'fourDigits';

  const shortAddress = form.short_address.trim().toUpperCase();
  if (shortAddress && !/^[A-Z]{4}\d{4}$/.test(shortAddress)) errors.short_address = 'shortAddress';

  if (Object.keys(errors).length > 0 || !phone) return { input: null, errors };

  return {
    errors,
    input: {
      recipient_name: recipientName,
      recipient_phone: phone,
      city,
      district,
      street,
      building_no: buildingNo,
      postal_code: postalCode,
      additional_no: additionalNo || null,
      short_address: shortAddress || null,
      is_default: form.is_default,
    },
  };
}
