import type { TextStyle } from 'react-native';

export const colors = {
  primary: '#0E7C66',
  primaryPressed: '#0A6352',
  primarySoft: '#E6F4F0',
  onPrimary: '#FFFFFF',

  text: '#101828',
  textMuted: '#475467',
  textSubtle: '#667085',

  background: '#F6F7F9',
  surface: '#FFFFFF',
  surfaceMuted: '#F2F4F7',
  border: '#E4E7EC',
  track: '#E4E7EC',

  success: '#067647',
  successSoft: '#DCFAE6',
  warning: '#B54708',
  warningSoft: '#FEF0C7',
  danger: '#B42318',
  dangerSoft: '#FEE4E2',
  info: '#175CD3',
  infoSoft: '#D1E9FF',
} as const;

export type ColorName = keyof typeof colors;

export const space = {
  xxs: 2,
  xs: 4,
  sm: 8,
  md: 12,
  lg: 16,
  xl: 24,
  xxl: 32,
} as const;

export const radius = {
  sm: 8,
  md: 12,
  lg: 16,
  pill: 999,
} as const;

/** IBM Plex Sans Arabic covers Arabic and Latin, so both languages share one family. */
export const fonts = {
  regular: 'IBMPlexSansArabic_400Regular',
  medium: 'IBMPlexSansArabic_500Medium',
  semibold: 'IBMPlexSansArabic_600SemiBold',
  bold: 'IBMPlexSansArabic_700Bold',
} as const;

export type FontWeightName = keyof typeof fonts;

export const typeScale = {
  display: { fontSize: 26, lineHeight: 38 },
  title: { fontSize: 20, lineHeight: 30 },
  heading: { fontSize: 17, lineHeight: 26 },
  body: { fontSize: 15, lineHeight: 24 },
  small: { fontSize: 13, lineHeight: 20 },
  tiny: { fontSize: 11, lineHeight: 16 },
} as const satisfies Record<string, TextStyle>;

export type TypeVariant = keyof typeof typeScale;

export const shadow = {
  card: {
    shadowColor: '#101828',
    shadowOpacity: 0.06,
    shadowRadius: 8,
    shadowOffset: { width: 0, height: 2 },
    elevation: 2,
  },
} as const;
