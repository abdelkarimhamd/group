import { Pressable, StyleSheet, View } from 'react-native';

import { colors, space, type ColorName } from '@/theme/tokens';

import { Icon, type IconName } from './Icon';
import { Text } from './Text';

/** A label on one side and a value on the other, for money summaries. */
export function DetailRow({
  label,
  value,
  emphasis = false,
  valueColor,
  testID,
}: {
  label: string;
  value: string;
  emphasis?: boolean;
  valueColor?: ColorName;
  testID?: string;
}) {
  return (
    <View style={styles.detailRow} testID={testID}>
      <Text
        variant={emphasis ? 'body' : 'small'}
        color={emphasis ? 'text' : 'textMuted'}
        weight={emphasis ? 'semibold' : 'regular'}
        style={styles.flex}>
        {label}
      </Text>
      <Text
        variant={emphasis ? 'heading' : 'body'}
        weight={emphasis ? 'bold' : 'medium'}
        color={valueColor ?? 'text'}>
        {value}
      </Text>
    </View>
  );
}

/** A tappable settings-style row. Without `onPress` it is shown but disabled, without the chevron. */
export function NavRow({
  icon,
  title,
  subtitle,
  onPress,
  danger = false,
  testID,
}: {
  icon: IconName;
  title: string;
  subtitle?: string;
  onPress?: () => void;
  danger?: boolean;
  testID?: string;
}) {
  return (
    <Pressable
      testID={testID}
      accessibilityRole="button"
      accessibilityState={{ disabled: !onPress }}
      disabled={!onPress}
      onPress={onPress}
      style={({ pressed }) => [styles.navRow, pressed && { backgroundColor: colors.surfaceMuted }]}>
      <Icon name={icon} size={22} color={danger ? 'danger' : 'textMuted'} />
      <View style={styles.flex}>
        <Text color={danger ? 'danger' : 'text'} weight="medium">
          {title}
        </Text>
        {subtitle ? (
          <Text variant="small" color="textMuted">
            {subtitle}
          </Text>
        ) : null}
      </View>
      {danger || !onPress ? null : <Icon name="chevron-forward" size={18} color="textSubtle" />}
    </Pressable>
  );
}

export function Divider() {
  return <View style={styles.divider} />;
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
  },
  detailRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.md,
    paddingVertical: space.xs,
  },
  navRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.md,
    paddingVertical: space.md,
    paddingHorizontal: space.lg,
  },
  divider: {
    height: StyleSheet.hairlineWidth,
    backgroundColor: colors.border,
  },
});
