import '@testing-library/jest-dom/vitest';
import { cleanup, configure } from '@testing-library/react';
import { afterAll, afterEach, beforeAll, beforeEach } from 'vitest';
import { writeToken } from '../auth/storage';
import { server } from './server';

// Screens load on demand, and the first load of one in a test run can take a while.
configure({ asyncUtilTimeout: 5000 });

// Ant Design reads these browser APIs, which jsdom does not provide.
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: (query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => {},
    removeListener: () => {},
    addEventListener: () => {},
    removeEventListener: () => {},
    dispatchEvent: () => false,
  }),
});

class ResizeObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
}
globalThis.ResizeObserver ??= ResizeObserverStub as unknown as typeof ResizeObserver;

// jsdom does not implement pseudo-element styles; antd asks for them to measure scrollbars.
const getComputedStyle = window.getComputedStyle.bind(window);
window.getComputedStyle = (element: Element, pseudo?: string | null) =>
  getComputedStyle(element, pseudo ? undefined : pseudo);

beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));

beforeEach(() => {
  // Tests read English text; one test switches to Arabic on purpose.
  localStorage.setItem('groupbuy.admin.locale', 'en');
});

afterEach(() => {
  cleanup();
  server.resetHandlers();
  writeToken(null);
  sessionStorage.clear();
  localStorage.clear();
});

afterAll(() => server.close());
