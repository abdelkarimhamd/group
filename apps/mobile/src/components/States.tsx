import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { errorMessage } from '@/api/errors';
import { useFormat } from '@/i18n/useFormat';
import { colors, space } from '@/theme/tokens';

import { Button } from './Button';
import { Icon, type IconName } from './Icon';
import { Text } from './Text';

export function LoadingState() {
  return (
    <View style={styles.center} testID="loading">
      <ActivityIndicator color={colors.primary} size="large" />
    </View>
  );
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const { t } = useFormat();
  return (
    <View style={styles.center} testID="error-state">
      <Icon name="alert-circle-outline" size={40} color="textSubtle" />
      <Text align="center" color="textMuted">
        {errorMessage(error)}
      </Text>
      {onRetry ? (
        <Button title={t('common.retry')} variant="secondary" compact onPress={onRetry} />
      ) : null}
    </View>
  );
}

export function EmptyState({
  icon,
  message,
  action,
}: {
  icon: IconName;
  message: string;
  action?: { title: string; onPress: () => void };
}) {
  return (
    <View style={styles.center} testID="empty-state">
      <View style={styles.iconCircle}>
        <Icon name={icon} size={32} color="primary" />
      </View>
      <Text align="center" color="textMuted">
        {message}
      </Text>
      {action ? <Button title={action.title} compact onPress={action.onPress} /> : null}
    </View>
  );
}

const styles = StyleSheet.create({
  center: {
    flexGrow: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: space.md,
    padding: space.xl,
    minHeight: 240,
  },
  iconCircle: {
    width: 64,
    height: 64,
    borderRadius: 32,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.primarySoft,
  },
});
