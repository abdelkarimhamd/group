import { address } from '@/test/fixtures';

import { addressToForm, EMPTY_ADDRESS, validateAddress } from '../address';

describe('validateAddress', () => {
  it('builds the API body from a valid form', () => {
    const { input, errors } = validateAddress({
      recipient_name: '  Noura Alotaibi ',
      recipient_phone: '٠٥١٢٣٤٥٦٧٨',
      city: 'Riyadh',
      district: 'Al Olaya',
      street: 'Olaya Main St',
      building_no: '٢٤٠١',
      postal_code: '12211',
      additional_no: '',
      short_address: 'rrrd2929',
      is_default: true,
    });
    expect(errors).toEqual({});
    expect(input).toEqual({
      recipient_name: 'Noura Alotaibi',
      recipient_phone: '+966512345678',
      city: 'Riyadh',
      district: 'Al Olaya',
      street: 'Olaya Main St',
      building_no: '2401',
      postal_code: '12211',
      additional_no: null,
      short_address: 'RRRD2929',
      is_default: true,
    });
  });

  it('flags every missing required field', () => {
    const { input, errors } = validateAddress(EMPTY_ADDRESS);
    expect(input).toBeNull();
    expect(errors).toEqual({
      recipient_name: 'required',
      recipient_phone: 'required',
      city: 'required',
      district: 'required',
      street: 'required',
      building_no: 'required',
      postal_code: 'required',
    });
  });

  it('checks National Address formats', () => {
    const { errors } = validateAddress({
      ...addressToForm(address()),
      recipient_phone: '0112345678',
      building_no: '123',
      postal_code: '1234',
      additional_no: '12',
      short_address: 'RRR2929',
    });
    expect(errors).toEqual({
      recipient_phone: 'phone',
      building_no: 'fourDigits',
      postal_code: 'fiveDigits',
      additional_no: 'fourDigits',
      short_address: 'shortAddress',
    });
  });

  it('round-trips a saved address', () => {
    const saved = address();
    const form = addressToForm(saved);
    expect(form.recipient_phone).toBe('0512345678');
    const { input } = validateAddress(form);
    expect(input).toMatchObject({
      recipient_phone: saved.recipient_phone,
      building_no: saved.building_no,
      additional_no: saved.additional_no,
      short_address: saved.short_address,
      is_default: true,
    });
  });
});
