import { StyleSheet, View } from 'react-native';

import { colors, radius, space, type ColorName } from '@/theme/tokens';

import { Icon, type IconName } from './Icon';
import { Text } from './Text';

type Tone = 'info' | 'warning' | 'success' | 'danger';

const tones: Record<Tone, { bg: string; fg: ColorName; icon: IconName }> = {
  info: { bg: colors.infoSoft, fg: 'info', icon: 'information-circle-outline' },
  warning: { bg: colors.warningSoft, fg: 'warning', icon: 'alert-circle-outline' },
  success: { bg: colors.successSoft, fg: 'success', icon: 'checkmark-circle-outline' },
  danger: { bg: colors.dangerSoft, fg: 'danger', icon: 'close-circle-outline' },
};

/** An inline message box. */
export function Notice({
  tone = 'info',
  message,
  icon,
}: {
  tone?: Tone;
  message: string;
  icon?: IconName;
}) {
  const t = tones[tone];
  return (
    <View style={[styles.box, { backgroundColor: t.bg }]} accessibilityRole="text">
      <Icon name={icon ?? t.icon} size={18} color={t.fg} />
      <Text variant="small" color="text" style={styles.text}>
        {message}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  box: {
    flexDirection: 'row',
    gap: space.sm,
    padding: space.md,
    borderRadius: radius.md,
    alignItems: 'flex-start',
  },
  text: {
    flex: 1,
  },
});
