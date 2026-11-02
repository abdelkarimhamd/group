/**
 * Keeps the two languages in step with each other, with the code and with the API contract:
 * every key the code reads exists, Arabic has every plural form, placeholders match, and every
 * status the API can send has a label.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { parse } from 'yaml';

import ar from '../ar';
import en from '../en';

type Tree = { [key: string]: string | Tree };

function flatten(tree: Tree, prefix = ''): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [key, value] of Object.entries(tree)) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (typeof value === 'string') out[path] = value;
    else Object.assign(out, flatten(value, path));
  }
  return out;
}

const PLURAL = /_(zero|one|two|few|many|other)$/;
const enKeys = flatten(en as unknown as Tree);
const arKeys = flatten(ar as unknown as Tree);
const base = (key: string) => key.replace(PLURAL, '');
const placeholders = (text: string) =>
  [...text.matchAll(/{{\s*(\w+)\s*}}/g)].map((m) => m[1]).sort();

/** True when `key` resolves directly or as a plural read through `tp()`. */
function hasKey(keys: Record<string, string>, key: string): boolean {
  return key in keys || `${key}_other` in keys;
}

describe('Arabic and English', () => {
  const enPlain = Object.keys(enKeys).filter((k) => !PLURAL.test(k));
  const arPlain = Object.keys(arKeys).filter((k) => !PLURAL.test(k));
  const pluralBases = [
    ...new Set(
      Object.keys(enKeys)
        .filter((k) => PLURAL.test(k))
        .map(base),
    ),
  ];

  it('have the same plain keys', () => {
    expect(arPlain.sort()).toEqual(enPlain.sort());
  });

  it('have the same plural keys, with every Arabic form', () => {
    const arBases = [
      ...new Set(
        Object.keys(arKeys)
          .filter((k) => PLURAL.test(k))
          .map(base),
      ),
    ];
    expect(arBases.sort()).toEqual([...pluralBases].sort());
    for (const key of pluralBases) {
      for (const form of ['one', 'other']) expect(enKeys).toHaveProperty([`${key}_${form}`]);
      for (const form of ['zero', 'one', 'two', 'few', 'many', 'other']) {
        expect(arKeys).toHaveProperty([`${key}_${form}`]);
      }
    }
  });

  it('use the same placeholders', () => {
    for (const key of enPlain) {
      expect([key, placeholders(arKeys[key])]).toEqual([key, placeholders(enKeys[key])]);
    }
  });

  it('have no empty strings', () => {
    for (const [key, value] of [...Object.entries(enKeys), ...Object.entries(arKeys)]) {
      expect([key, value.trim().length > 0]).toEqual([key, true]);
    }
  });
});

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) return name === '__tests__' ? [] : sourceFiles(path);
    return /\.(ts|tsx)$/.test(name) && !name.endsWith('.d.ts') ? [path] : [];
  });
}

describe('keys used in the code', () => {
  const src = join(__dirname, '..', '..');
  const sections = Object.keys(en).join('|');
  const keyLiteral = new RegExp(`['"]((?:${sections})\\.[A-Za-z0-9_.]+)['"]`, 'g');

  it('all exist', () => {
    const missing: string[] = [];
    let found = 0;
    for (const file of sourceFiles(src)) {
      if (/[\\/]i18n[\\/](en|ar)\.ts$/.test(file)) continue;
      const text = readFileSync(file, 'utf8');
      for (const [, key] of text.matchAll(keyLiteral)) {
        found += 1;
        if (!hasKey(enKeys, key)) missing.push(`${file.slice(src.length + 1)}: ${key}`);
      }
    }
    expect(found).toBeGreaterThan(100);
    expect(missing).toEqual([]);
  });
});

describe('labels for everything the API can send', () => {
  const contract = parse(
    readFileSync(join(__dirname, '..', '..', '..', '..', 'api', 'openapi', 'v1.yaml'), 'utf8'),
  );
  const schemas = contract.components.schemas;
  const enumOf = (name: string): string[] => schemas[name].enum;
  const propertyEnum = (name: string, property: string): string[] => {
    const schema = schemas[name];
    const properties =
      schema.properties ?? schema.allOf.find((part: Tree) => part.properties).properties;
    return properties[property].enum;
  };

  const cases: [string, string[]][] = [
    ['campaignStatus.', enumOf('CustomerCampaignStatus')],
    ['joins.status_', enumOf('ParticipationStatus')],
    ['joins.help_', enumOf('ParticipationStatus')],
    ['orders.status_', enumOf('OrderStatus')],
    ['orders.shipment_', enumOf('ShipmentStatus')],
    ['orders.invoice_', propertyEnum('InvoiceRef', 'type')],
    ['pay.rejection_', enumOf('PaymentRejectionReason')],
    ['campaign.cancelRule_', propertyEnum('CampaignDetail', 'cancellation_rule')],
    ['language.', enumOf('Locale')],
    ['validation.', ['required', 'phone', 'fourDigits', 'fiveDigits', 'shortAddress']],
  ];

  it.each(cases)('%s*', (prefix, values) => {
    expect(values.length).toBeGreaterThan(0);
    const missing = values.filter((value) => !hasKey(enKeys, `${prefix}${value}`));
    expect(missing).toEqual([]);
  });
});
