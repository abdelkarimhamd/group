#!/usr/bin/env node
/**
 * Fails when src/api/schema.d.ts is out of date with apps/api/openapi/v1.yaml.
 * Fix with `npm run api:types` and commit the result.
 */
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const bin = (name) => join(root, 'node_modules', '.bin', name);
const committed = join(root, 'src', 'api', 'schema.d.ts');
const dir = mkdtempSync(join(tmpdir(), 'api-types-'));
const fresh = join(dir, 'schema.d.ts');

try {
  execFileSync(bin('openapi-typescript'), ['../api/openapi/v1.yaml', '-o', fresh], {
    cwd: root,
    stdio: ['ignore', 'ignore', 'inherit'],
  });
  // Format with the project's Prettier config, as `npm run api:types` does.
  const formatted = execFileSync(
    bin('prettier'),
    ['--config', join(root, '.prettierrc.json'), '--stdin-filepath', committed],
    { cwd: root, input: readFileSync(fresh), encoding: 'utf8' },
  );
  if (formatted !== readFileSync(committed, 'utf8')) {
    console.error(
      'src/api/schema.d.ts does not match apps/api/openapi/v1.yaml.\n' +
        'Run `npm run api:types` in apps/mobile and commit the result.',
    );
    process.exit(1);
  }
  console.log('src/api/schema.d.ts matches the API contract.');
} finally {
  rmSync(dir, { recursive: true, force: true });
}
