import { formatMinor, formatMoney, multiplyMinor, savingMinor } from '../money';

const LRI = '⁦';
const PDI = '⁩';
const bare = (text: string) => text.replaceAll(LRI, '').replaceAll(PDI, '');

describe('formatMinor', () => {
  it('formats halalas without floating point', () => {
    expect(bare(formatMinor(13800, 'SAR', 'en'))).toBe('138.00 SAR');
    expect(bare(formatMinor(13805, 'SAR', 'en'))).toBe('138.05 SAR');
    expect(bare(formatMinor(5, 'SAR', 'en'))).toBe('0.05 SAR');
    expect(bare(formatMinor(0, 'SAR', 'en'))).toBe('0.00 SAR');
  });

  it('groups thousands', () => {
    expect(bare(formatMinor(123456789, 'SAR', 'en'))).toBe('1,234,567.89 SAR');
  });

  it('uses the Arabic currency label and keeps the number isolated', () => {
    expect(formatMinor(6900, 'SAR', 'ar')).toBe(`${LRI}69.00${PDI} ر.س`);
  });

  it('drops .00 only in compact mode and only for whole amounts', () => {
    expect(bare(formatMinor(6900, 'SAR', 'en', { compact: true }))).toBe('69 SAR');
    expect(bare(formatMinor(6950, 'SAR', 'en', { compact: true }))).toBe('69.50 SAR');
  });

  it('keeps the sign inside the isolate', () => {
    expect(formatMinor(-2500, 'SAR', 'ar')).toBe(`${LRI}-25.00${PDI} ر.س`);
  });

  it('refuses fractional minor units', () => {
    expect(() => formatMinor(10.5, 'SAR', 'en')).toThrow(RangeError);
    expect(() => formatMinor(Number.NaN, 'SAR', 'en')).toThrow(RangeError);
  });

  it('formats an API money object', () => {
    const money = { amount_minor: 17800, currency: 'SAR' as const, formatted: '178.00 SAR' };
    expect(bare(formatMoney(money, 'en'))).toBe('178.00 SAR');
  });
});

describe('multiplyMinor', () => {
  it('multiplies integer units', () => {
    expect(multiplyMinor(6900, 2)).toBe(13800);
    expect(multiplyMinor(6900, 0)).toBe(0);
  });

  it('refuses bad quantities and overflow', () => {
    expect(() => multiplyMinor(6900, 1.5)).toThrow(RangeError);
    expect(() => multiplyMinor(6900, -1)).toThrow(RangeError);
    expect(() => multiplyMinor(Number.MAX_SAFE_INTEGER, 2)).toThrow(RangeError);
  });
});

describe('savingMinor', () => {
  it('is never negative', () => {
    expect(savingMinor(7900, 6000)).toBe(1900);
    expect(savingMinor(6000, 7900)).toBe(0);
  });
});
