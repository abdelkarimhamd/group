import { ActivityIndicator, Pressable, StyleSheet, View, type ViewStyle } from 'react-native';

import { colors, radius, space, type ColorName } from '@/theme/tokens';

import { Icon, type IconName } from './Icon';
import { Text } from './Text';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';

const palette: Record<Variant, { bg: string; bgPressed: string; fg: ColorName; border?: string }> =
  {
    primary: { bg: colors.primary, bgPressed: colors.primaryPressed, fg: 'onPrimary' },
    secondary: {
      bg: colors.surface,
      bgPressed: colors.surfaceMuted,
      fg: 'primary',
      border: colors.border,
    },
    ghost: { bg: 'transparent', bgPressed: colors.surfaceMuted, fg: 'primary' },
    danger: {
      bg: colors.surface,
      bgPressed: colors.dangerSoft,
      fg: 'danger',
      border: colors.border,
    },
  };

export function Button({
  title,
  onPress,
  variant = 'primary',
  icon,
  loading = false,
  disabled = false,
  compact = false,
  style,
  testID,
}: {
  title: string;
  onPress?: () => void;
  variant?: Variant;
  icon?: IconName;
  loading?: boolean;
  disabled?: boolean;
  compact?: boolean;
  style?: ViewStyle;
  testID?: string;
}) {
  const p = palette[variant];
  const inactive = disabled || loading;
  return (
    <Pressable
      testID={testID}
      accessibilityRole="button"
      accessibilityState={{ disabled: inactive, busy: loading }}
      disabled={inactive}
      onPress={onPress}
      style={({ pressed }) => [
        styles.base,
        compact && styles.compact,
        {
          backgroundColor: pressed ? p.bgPressed : p.bg,
          borderColor: p.border ?? 'transparent',
          opacity: disabled ? 0.5 : 1,
        },
        style,
      ]}>
      {loading ? (
        <ActivityIndicator color={colors[p.fg]} />
      ) : (
        <View style={styles.content}>
          {icon ? <Icon name={icon} size={18} color={p.fg} /> : null}
          <Text variant={compact ? 'small' : 'body'} weight="semibold" color={p.fg}>
            {title}
          </Text>
        </View>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  base: {
    minHeight: 50,
    borderRadius: radius.md,
    borderWidth: 1,
    paddingHorizontal: space.lg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  compact: {
    minHeight: 36,
    paddingHorizontal: space.md,
    borderRadius: radius.sm,
  },
  content: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.sm,
  },
});
