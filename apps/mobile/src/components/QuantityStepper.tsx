import { Pressable, StyleSheet, View } from 'react-native';

import { useFormat } from '@/i18n/useFormat';
import { colors, radius, space } from '@/theme/tokens';

import { Icon } from './Icon';
import { Text } from './Text';

export function QuantityStepper({
  value,
  min = 1,
  max,
  onChange,
}: {
  value: number;
  min?: number;
  max: number;
  onChange: (value: number) => void;
}) {
  const { t } = useFormat();
  const canDecrease = value > min;
  const canIncrease = value < max;
  return (
    <View style={styles.row} accessibilityLabel={t('campaign.quantity')}>
      <StepButton
        icon="remove"
        disabled={!canDecrease}
        onPress={() => onChange(value - 1)}
        testID="qty-decrease"
      />
      <Text variant="title" style={styles.value} testID="qty-value">
        {value}
      </Text>
      <StepButton
        icon="add"
        disabled={!canIncrease}
        onPress={() => onChange(value + 1)}
        testID="qty-increase"
      />
    </View>
  );
}

function StepButton({
  icon,
  disabled,
  onPress,
  testID,
}: {
  icon: 'add' | 'remove';
  disabled: boolean;
  onPress: () => void;
  testID: string;
}) {
  return (
    <Pressable
      testID={testID}
      accessibilityRole="button"
      accessibilityState={{ disabled }}
      disabled={disabled}
      onPress={onPress}
      hitSlop={8}
      style={({ pressed }) => [
        styles.button,
        pressed && { backgroundColor: colors.surfaceMuted },
        disabled && { opacity: 0.4 },
      ]}>
      <Icon name={icon} size={20} color="primary" />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.md,
  },
  button: {
    width: 40,
    height: 40,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.surface,
  },
  value: {
    minWidth: 32,
    textAlign: 'center',
  },
});
