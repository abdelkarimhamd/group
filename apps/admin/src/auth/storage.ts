const TOKEN_KEY = 'groupbuy.admin.token';

/**
 * The admin token lives in sessionStorage: it is gone when the tab closes and
 * is never shared with other tabs. Storage can be blocked, so every access is
 * guarded and the portal falls back to keeping the token in memory.
 */
let memoryToken: string | null = null;

export function readToken(): string | null {
  try {
    return globalThis.sessionStorage?.getItem(TOKEN_KEY) ?? memoryToken;
  } catch {
    return memoryToken;
  }
}

export function writeToken(token: string | null): void {
  memoryToken = token;
  try {
    if (token) globalThis.sessionStorage?.setItem(TOKEN_KEY, token);
    else globalThis.sessionStorage?.removeItem(TOKEN_KEY);
  } catch {
    // Storage blocked; the in-memory copy is used instead.
  }
}
