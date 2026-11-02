import { Platform, type ViewStyle } from 'react-native';

/**
 * An absolute offset from the start or end edge. Native layouts resolve `start`/`end` from the
 * app's layout direction; react-native-web resolves them as left/right regardless of the
 * page's `dir`, so the browser preview gets the physical side.
 */
export function inset(
  side: 'start' | 'end',
  value: number | `${number}%`,
  rtl: boolean,
): ViewStyle {
  if (Platform.OS !== 'web') return side === 'start' ? { start: value } : { end: value };
  const left = (side === 'start') !== rtl;
  return left ? { left: value } : { right: value };
}
