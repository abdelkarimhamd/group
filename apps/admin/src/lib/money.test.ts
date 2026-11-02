import { describe, expect, it } from 'vitest';
import {
  formatBasisPoints,
  formatMinor,
  minorToMajorText,
  normalizeDigits,
  parseMajorToMinor,
  sumMinor,
} from './money';

describe('parseMajorToMinor', () => {
  it.each([
    ['69', 6900],
    ['69.5', 6950],
    ['69.50', 6950],
    ['0.01', 1],
    ['1,234.50', 123450],
    [' 12 ', 1200],
    ['٦٩٫٥', 6950],
    ['۱۲۳', 12300],
    ['0', 0],
  ])('reads %j as %d halalas', (input, expected) => {
    expect(parseMajorToMinor(input)).toBe(expected);
  });

  it.each(['', '-1', '1.234', 'abc', '1e3', '.5', '12.3.4', '99999999999999'])(
    'refuses %j',
    (input) => {
      expect(parseMajorToMinor(input)).toBeNull();
    },
  );

  it('never goes through a float', () => {
    // 0.1 + 0.2 style errors would turn 1.15 into 114 or 0.29 into 28.
    expect(parseMajorToMinor('1.15')).toBe(115);
    expect(parseMajorToMinor('0.29')).toBe(29);
    expect(parseMajorToMinor('9007199254740.99')).toBe(900719925474099);
  });
});

describe('formatting', () => {
  it('shows minor units as major text without division', () => {
    expect(minorToMajorText(6900)).toBe('69.00');
    expect(minorToMajorText(5)).toBe('0.05');
    expect(minorToMajorText(-150)).toBe('-1.50');
    expect(formatMinor(123456789, 'SAR')).toBe('1,234,567.89 SAR');
    expect(formatMinor(-5, 'USD')).toBe('-0.05 USD');
  });

  it('refuses amounts that are not integers', () => {
    expect(() => formatMinor(10.5, 'SAR')).toThrow(TypeError);
    expect(() => minorToMajorText(Number.NaN)).toThrow(TypeError);
  });

  it('formats basis points as a percentage', () => {
    expect(formatBasisPoints(1500)).toBe('15.00%');
    expect(formatBasisPoints(905)).toBe('9.05%');
    expect(formatBasisPoints(-250)).toBe('-2.50%');
  });

  it('normalizes Arabic-Indic digits', () => {
    expect(normalizeDigits('٠١٢٣٤٥٦٧٨٩')).toBe('0123456789');
  });
});

describe('sumMinor', () => {
  it('adds integers exactly', () => {
    expect(sumMinor([100, 250, 5])).toBe(355);
    expect(sumMinor([])).toBe(0);
  });

  it('refuses non-integers and overflow', () => {
    expect(() => sumMinor([1, 0.5])).toThrow(TypeError);
    expect(() => sumMinor([Number.MAX_SAFE_INTEGER, 1])).toThrow(RangeError);
  });
});
