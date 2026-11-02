import { StyleSheet, View, type ViewProps } from 'react-native';

import { colors, radius, shadow, space } from '@/theme/tokens';

export function Card({ style, padded = true, ...rest }: ViewProps & { padded?: boolean }) {
  return <View {...rest} style={[styles.card, padded && styles.padded, style]} />;
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.surface,
    borderRadius: radius.lg,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
    ...shadow.card,
  },
  padded: {
    padding: space.lg,
  },
});
