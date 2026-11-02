import { router } from 'expo-router';
import { StyleSheet, View } from 'react-native';

import { useFormat } from '@/i18n/useFormat';
import { colors, space } from '@/theme/tokens';

import { Button } from './Button';
import { Icon } from './Icon';
import { Text } from './Text';

/** Shown on tabs that need an account. */
export function SignInPrompt({ next }: { next?: string }) {
  const { t } = useFormat();
  return (
    <View style={styles.container} testID="sign-in-prompt">
      <View style={styles.iconCircle}>
        <Icon name="person-circle-outline" size={36} color="primary" />
      </View>
      <Text variant="heading" align="center">
        {t('account.signedOutTitle')}
      </Text>
      <Text color="textMuted" align="center">
        {t('account.signedOutBody')}
      </Text>
      <Button
        title={t('common.signIn')}
        onPress={() => router.push({ pathname: '/sign-in', params: next ? { next } : {} })}
        style={styles.button}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: space.md,
    padding: space.xl,
    backgroundColor: colors.background,
  },
  iconCircle: {
    width: 72,
    height: 72,
    borderRadius: 36,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.primarySoft,
  },
  button: {
    alignSelf: 'stretch',
  },
});
