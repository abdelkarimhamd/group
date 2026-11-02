import { storage } from '@/lib/storage';

const TOKEN_KEY = 'session.token';

let current: string | null = storage.get(TOKEN_KEY);
const invalidationListeners = new Set<() => void>();

/** The customer's bearer token, kept in the Keychain/Keystore between launches. */
export const authToken = {
  get(): string | null {
    return current;
  },

  set(token: string): void {
    current = token;
    storage.set(TOKEN_KEY, token);
  },

  async clear(): Promise<void> {
    current = null;
    await storage.remove(TOKEN_KEY);
  },

  /** The API refused the token (expired or revoked): forget it and tell the session. */
  invalidate(): void {
    if (!current) return;
    current = null;
    void storage.remove(TOKEN_KEY);
    invalidationListeners.forEach((listener) => listener());
  },

  onInvalidated(listener: () => void): () => void {
    invalidationListeners.add(listener);
    return () => {
      invalidationListeners.delete(listener);
    };
  },
};
