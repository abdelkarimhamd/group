import { describe, expect, it } from 'vitest';
import { generatePassword, PASSWORD_MIN_LENGTH } from './password';
import { formatSaudiMobile, normalizeSaudiMobile } from './phone';

describe('normalizeSaudiMobile', () => {
  it.each([
    '0512345678',
    '512345678',
    '+966512345678',
    '966512345678',
    '00966512345678',
    '051 234 5678',
    '٠٥١٢٣٤٥٦٧٨',
  ])('reads %j', (input) => {
    expect(normalizeSaudiMobile(input)).toBe('+966512345678');
  });

  it.each(['0412345678', '05123', '+971501234567', 'phone'])('refuses %j', (input) => {
    expect(normalizeSaudiMobile(input)).toBeNull();
  });

  it('formats for reading', () => {
    expect(formatSaudiMobile('+966512345678')).toBe('+966 51 234 5678');
    expect(formatSaudiMobile(null)).toBe('—');
  });
});

describe('generatePassword', () => {
  it('makes long, varied passwords without look-alike characters', () => {
    const a = generatePassword();
    expect(a.length).toBeGreaterThanOrEqual(PASSWORD_MIN_LENGTH);
    expect(a).not.toMatch(/[0O1lI]/);
    expect(generatePassword()).not.toBe(a);
  });
});
