/**
 * Test doubles for native modules. Each test file gets a fresh module registry, so the
 * in-memory stores below start empty for every file.
 */
jest.mock('expo-secure-store', () => {
  const store = new Map<string, string>();
  return {
    getItem: (key: string) => store.get(key) ?? null,
    setItem: (key: string, value: string) => void store.set(key, value),
    deleteItemAsync: async (key: string) => void store.delete(key),
    __store: store,
  };
});

jest.mock('expo-crypto', () => ({
  randomUUID: () => jest.requireActual<typeof import('node:crypto')>('node:crypto').randomUUID(),
}));

// Tests read English unless they switch to Arabic, so failures are easy to read.
jest.mock('expo-localization', () => ({
  getLocales: () => [{ languageCode: 'en', languageTag: 'en-SA', textDirection: 'ltr' }],
}));

jest.mock('expo-device', () => ({
  modelName: 'Test phone',
  osName: 'iOS',
  osVersion: '19.0',
}));

// Icon fonts load through the native asset registry, which tests do not have.
jest.mock('@expo/vector-icons/Ionicons', () => {
  const { Text } = jest.requireActual<typeof import('react-native')>('react-native');
  const { createElement } = jest.requireActual<typeof import('react')>('react');
  function Ionicons({ name, testID }: { name: string; testID?: string }) {
    return createElement(Text, { testID: testID ?? `icon-${name}` }, '');
  }
  Ionicons.font = {};
  Ionicons.glyphMap = new Proxy({}, { has: () => true, get: () => 0 });
  return { __esModule: true, default: Ionicons };
});
