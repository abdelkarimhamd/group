import { Modal, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { Button } from '@/components/Button';
import { Icon } from '@/components/Icon';
import { Notice } from '@/components/Notice';
import { Text } from '@/components/Text';
import { useFormat } from '@/i18n/useFormat';
import { colors, radius, space } from '@/theme/tokens';

import type { PaymentSheetProps } from './types';

/**
 * Stands in for the Moyasar form until that integration lands. It never sees a card: it hands
 * back a provider payment ID that the API's fake Moyasar adapter understands (agreed with the
 * API thread for E9):
 *
 * - `fake_authorized_<payment_id>`: an authorize-only hold for exactly the payment amount.
 * - `fake_declined_<payment_id>`: a declined card, so the retry flow can be tested.
 *
 * Against the Prism mock, sync answers with the contract's example whatever the ID.
 */
export function fakeProviderPaymentId(
  outcome: 'authorized' | 'declined',
  paymentId: string,
): string {
  return `fake_${outcome}_${paymentId}`;
}

export function FakePaymentSheet({ visible, paymentId, checkout, onResult }: PaymentSheetProps) {
  const { t, minor } = useFormat();
  return (
    <Modal
      visible={visible}
      animationType="slide"
      presentationStyle="pageSheet"
      onRequestClose={() => onResult({ kind: 'dismissed' })}>
      <SafeAreaView style={styles.sheet} edges={['top', 'bottom']}>
        <View style={styles.header}>
          <Text variant="title">{t('fakePay.title')}</Text>
          <Button
            variant="ghost"
            compact
            title={t('common.cancel')}
            onPress={() => onResult({ kind: 'dismissed' })}
          />
        </View>
        <Notice tone="warning" message={t('fakePay.notice')} />
        <View style={styles.amountBox}>
          <Icon name="card-outline" size={28} color="primary" />
          <Text variant="small" color="textMuted">
            {t('fakePay.amount')}
          </Text>
          <Text variant="display" testID="fake-pay-amount">
            {minor(checkout.amount, checkout.currency)}
          </Text>
          <Text variant="small" color="textSubtle">
            {checkout.description}
          </Text>
        </View>
        <View style={styles.actions}>
          <Button
            testID="fake-pay-approve"
            title={t('fakePay.approve')}
            onPress={() =>
              onResult({
                kind: 'completed',
                providerPaymentId: fakeProviderPaymentId('authorized', paymentId),
              })
            }
          />
          <Button
            testID="fake-pay-decline"
            variant="danger"
            title={t('fakePay.decline')}
            onPress={() =>
              onResult({
                kind: 'completed',
                providerPaymentId: fakeProviderPaymentId('declined', paymentId),
              })
            }
          />
        </View>
      </SafeAreaView>
    </Modal>
  );
}

const styles = StyleSheet.create({
  sheet: {
    flex: 1,
    backgroundColor: colors.background,
    padding: space.lg,
    gap: space.lg,
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  amountBox: {
    alignItems: 'center',
    gap: space.xs,
    padding: space.xl,
    borderRadius: radius.lg,
    backgroundColor: colors.surface,
    borderWidth: 1,
    borderColor: colors.border,
  },
  actions: {
    marginTop: 'auto',
    gap: space.md,
  },
});
