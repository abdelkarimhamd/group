import type { Href } from 'expo-router';

/**
 * A `next` route passed through sign-in. Only in-app paths are allowed, so a crafted deep
 * link cannot send the customer elsewhere after they sign in.
 */
export function safeNextPath(next: string | string[] | undefined): Href | null {
  const value = Array.isArray(next) ? next[0] : next;
  if (!value || !value.startsWith('/') || value.startsWith('//') || value.includes('://')) {
    return null;
  }
  return value as Href;
}
