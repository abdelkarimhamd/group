#!/usr/bin/env node
/**
 * Starts a Prism mock of the API contract with request validation on, runs the contract
 * tests against it, and stops it again. Exit code is the test run's.
 */
import { spawn } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const contract = resolve(root, '../api/openapi/v1.yaml');
const port = process.env.CONTRACT_PORT ?? '4011';
const url = `http://127.0.0.1:${port}`;

const bin = (name) => resolve(root, 'node_modules', '.bin', name);

// Own process group, so stopping it also stops anything Prism forked.
const prism = spawn(
  bin('prism'),
  ['mock', contract, '--port', port, '--host', '127.0.0.1', '--errors', '-v', 'warn'],
  { cwd: root, stdio: ['ignore', 'inherit', 'inherit'], detached: true },
);
const stopPrism = () => {
  try {
    process.kill(-prism.pid, 'SIGTERM');
  } catch {
    // Already stopped.
  }
};
process.on('SIGINT', () => {
  stopPrism();
  process.exit(130);
});

async function waitForPrism() {
  const deadline = Date.now() + 60_000;
  while (Date.now() < deadline) {
    if (prism.exitCode !== null) throw new Error(`Prism exited with ${prism.exitCode}`);
    try {
      await fetch(`${url}/categories`);
      return;
    } catch {
      await new Promise((r) => setTimeout(r, 500));
    }
  }
  throw new Error('Prism did not start within 60 seconds');
}

let code = 1;
try {
  await waitForPrism();
  const jest = spawn(
    bin('jest'),
    [
      '--testPathIgnorePatterns',
      '/node_modules/',
      '--testMatch',
      '**/*.contract.test.ts',
      ...process.argv.slice(2),
    ],
    { cwd: root, stdio: 'inherit', env: { ...process.env, CONTRACT_API_URL: url } },
  );
  code = await new Promise((done) => jest.on('exit', (c) => done(c ?? 1)));
} catch (error) {
  console.error(error);
} finally {
  stopPrism();
}
process.exit(code);
