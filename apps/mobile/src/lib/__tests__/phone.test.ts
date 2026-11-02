import { digitsOnly, formatSaudiMobile, normalizeSaudiMobile, toAsciiDigits } from '../phone';

describe('normalizeSaudiMobile', () => {
  it.each([
    ['0512345678', '+966512345678'],
    ['512345678', '+966512345678'],
    ['+966512345678', '+966512345678'],
    ['00966512345678', '+966512345678'],
    ['966512345678', '+966512345678'],
    ['051 234 5678', '+966512345678'],
    ['051-234-5678', '+966512345678'],
    ['٠٥١٢٣٤٥٦٧٨', '+966512345678'],
    ['۰۵۱۲۳۴۵۶۷۸', '+966512345678'],
  ])('accepts %s', (input, expected) => {
    expect(normalizeSaudiMobile(input)).toBe(expected);
  });

  it.each(['', '0112345678', '05123456', '05123456789', '+971512345678', 'abc'])(
    'rejects %s',
    (input) => {
      expect(normalizeSaudiMobile(input)).toBeNull();
    },
  );
});

describe('formatSaudiMobile', () => {
  it('groups the number and isolates it by default', () => {
    expect(formatSaudiMobile('+966512345678')).toBe('⁦+966 51 234 5678⁩');
    expect(formatSaudiMobile('+966512345678', { isolate: false })).toBe('+966 51 234 5678');
  });

  it('leaves unknown shapes alone', () => {
    expect(formatSaudiMobile('+15551234567', { isolate: false })).toBe('+15551234567');
  });
});

describe('digits', () => {
  it('converts Arabic-Indic digits', () => {
    expect(toAsciiDigits('١٢٣ abc ۴۵۶')).toBe('123 abc 456');
  });

  it('keeps only digits up to a length', () => {
    expect(digitsOnly('12-34 56')).toBe('123456');
    expect(digitsOnly('١٢٣٤٥٦٧٨', 6)).toBe('123456');
  });
});
