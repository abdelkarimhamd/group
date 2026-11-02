/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react';
import { defineConfig, loadEnv } from 'vite';

// The portal always calls /api/v1 on its own origin. In development, Vite
// forwards those calls either to the Prism mock built from the contract
// (VITE_API_TARGET=mock, the default until the endpoints ship) or to Laravel.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const target = env.VITE_API_TARGET ?? 'mock';
  const proxy =
    target === 'mock'
      ? {
          '/api/v1': {
            target: env.MOCK_API_URL ?? 'http://127.0.0.1:4010',
            changeOrigin: true,
            rewrite: (path: string) => path.replace(/^\/api\/v1/, ''),
          },
        }
      : {
          '/api/v1': {
            target: env.LARAVEL_API_URL ?? 'http://127.0.0.1:8000',
            changeOrigin: true,
          },
        };

  return {
    plugins: [react()],
    server: { port: 5174, proxy },
    preview: { port: 5174, proxy },
    // Screens load on demand; the shared chunk is mostly Ant Design (about 880 kB, 285 kB gzipped).
    build: { chunkSizeWarningLimit: 1000 },
    test: {
      environment: 'jsdom',
      globals: true,
      setupFiles: ['./src/test/setup.ts'],
      css: false,
      testTimeout: 20000,
    },
  };
});
