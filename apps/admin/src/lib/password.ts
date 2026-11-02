/** No look-alike characters (0/O, 1/l/I), so a password read aloud survives. */
const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

/** A random password for a new admin, from the browser's secure random source. */
export function generatePassword(length = 16): string {
  const out: string[] = [];
  const limit = 256 - (256 % ALPHABET.length);
  while (out.length < length) {
    const bytes = new Uint8Array(length * 2);
    globalThis.crypto.getRandomValues(bytes);
    for (const byte of bytes) {
      // Rejection sampling keeps every character equally likely.
      if (byte < limit && out.length < length) out.push(ALPHABET[byte % ALPHABET.length]!);
    }
  }
  return out.join('');
}

export const PASSWORD_MIN_LENGTH = 12;
export const PASSWORD_MAX_LENGTH = 200;
