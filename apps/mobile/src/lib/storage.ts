import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

/**
 * Small synchronous key-value store. The Keychain/Keystore on devices; localStorage in the
 * browser preview, which is for development only.
 */
export const storage = {
  get(key: string): string | null {
    try {
      if (Platform.OS === 'web') {
        return globalThis.localStorage?.getItem(key) ?? null;
      }
      return SecureStore.getItem(key);
    } catch {
      return null;
    }
  },

  set(key: string, value: string): void {
    try {
      if (Platform.OS === 'web') {
        globalThis.localStorage?.setItem(key, value);
        return;
      }
      SecureStore.setItem(key, value);
    } catch {
      // Losing a preference is not worth crashing for.
    }
  },

  async remove(key: string): Promise<void> {
    try {
      if (Platform.OS === 'web') {
        globalThis.localStorage?.removeItem(key);
        return;
      }
      await SecureStore.deleteItemAsync(key);
    } catch {
      // Already gone.
    }
  },
};
