import { useMutation } from '@tanstack/react-query';
import { Redirect, router } from 'expo-router';
import { Alert, StyleSheet, View } from 'react-native';

import { api } from '@/api/endpoints';
import { errorMessage, isApiError } from '@/api/errors';
import { useAuth } from '@/auth/session';
import { Button } from '@/components/Button';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import { Notice } from '@/components/Notice';
import { Screen } from '@/components/Screen';
import { Text } from '@/components/Text';
import { useFormat } from '@/i18n/useFormat';
import { deletionBlockers } from '@/lib/account';
import { confirmAction } from '@/lib/confirm';
import { space } from '@/theme/tokens';

/**
 * Account deletion, which both app stores require in the app (PDPL erasure on the API).
 * Personal data goes; orders and invoices stay, as tax law requires. The API refuses while a
 * join, an order, a hold or a refund is still open, and says which.
 */
export default function DeleteAccountScreen() {
  const { t } = useFormat();
  const { isSignedIn, signOut } = useAuth();

  const remove = useMutation({
    mutationFn: () => api.deleteMe(),
    onSuccess: async () => {
      // Every token of the account stopped working: forget it here without signing out.
      await signOut({ revoke: false });
      router.dismissTo('/account');
      Alert.alert(t('deleteAccount.doneTitle'), t('deleteAccount.doneBody'));
    },
  });

  if (!isSignedIn && !remove.isSuccess) return <Redirect href="/account" />;

  const blockers = deletionBlockers(remove.error);

  const ask = async () => {
    const ok = await confirmAction({
      title: t('deleteAccount.confirmTitle'),
      message: t('deleteAccount.confirmBody'),
      confirmText: t('deleteAccount.button'),
      cancelText: t('common.cancel'),
      destructive: true,
    });
    if (ok) remove.mutate();
  };

  return (
    <Screen
      footer={
        <Button
          testID="confirm-delete-account"
          variant="danger"
          title={t('deleteAccount.button')}
          loading={remove.isPending}
          onPress={() => void ask()}
        />
      }>
      <Icon name="trash-outline" size={40} color="danger" />
      {blockers ? (
        <Card style={styles.gap} testID="deletion-blocked">
          <Text weight="semibold">{t('deleteAccount.blockedTitle')}</Text>
          {blockers.map((reason) => (
            <Text key={reason} variant="small" color="textMuted">
              {t(`deleteAccount.blocked_${reason}`)}
            </Text>
          ))}
        </Card>
      ) : remove.error ? (
        <Notice
          tone="danger"
          message={isApiError(remove.error) ? errorMessage(remove.error) : t('errors.generic')}
        />
      ) : null}
      <Card style={styles.gap}>
        <Text>{t('deleteAccount.erased')}</Text>
        <Text color="textMuted">{t('deleteAccount.kept')}</Text>
        <Text color="textMuted">{t('deleteAccount.again')}</Text>
      </Card>
      <View>
        <Text weight="semibold" color="danger">
          {t('deleteAccount.final')}
        </Text>
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  gap: {
    gap: space.sm,
  },
});
