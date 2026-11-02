import { pluralCategory } from '../plural';

describe('pluralCategory', () => {
  it('follows CLDR for Arabic', () => {
    const cases: [number, string][] = [
      [0, 'zero'],
      [1, 'one'],
      [2, 'two'],
      [3, 'few'],
      [10, 'few'],
      [11, 'many'],
      [47, 'many'],
      [99, 'many'],
      [100, 'other'],
      [102, 'other'],
      [103, 'few'],
      [111, 'many'],
    ];
    for (const [n, category] of cases) expect([n, pluralCategory('ar', n)]).toEqual([n, category]);
  });

  it('follows CLDR for English', () => {
    expect(pluralCategory('en', 0)).toBe('other');
    expect(pluralCategory('en', 1)).toBe('one');
    expect(pluralCategory('en', 2)).toBe('other');
  });
});
