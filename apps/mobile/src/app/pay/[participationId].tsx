import { useMutation, useQueryClient } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useState, type ReactNode } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { api } from '@/api/endpoints';
import { errorMessage } from '@/api/errors';
import { queryKeys, useParticipation } from '@/api/queries';
import type { Participation } from '@/api/types';
import { Button } from '@/components/Button';
import { Card } from '@/components/Card';
import { Icon, type IconName } from '@/components/Icon';
import { Notice } from '@/components/Notice';
import { DetailRow, Divider } from '@/components/Rows';
import { Screen } from '@/components/Screen';
import { ErrorState, LoadingState } from '@/components/States';
import { Text } from '@/components/Text';
import { useNow } from '@/components/useNow';
import { clock, useFormat } from '@/i18n/useFormat';
import { PaymentSheet, type PaymentSheetResult } from '@/payments';
import { payView } from '@/lib/payment';
import { remainingUntil } from '@/lib/time';
import { colors, space, type ColorName } from '@/theme/tokens';

export default function PayScreen() {
  const { participationId } = useLocalSearchParams<{ participationId: string }>();
  const participation = useParticipation(participationId);
  if (participation.isPending) return <LoadingState />;
  if (participation.isError) {
    return <ErrorState error={participation.error} onRetry={() => void participation.refetch()} />;
  }
  return <PayFlow participation={participation.data} />;
}

function PayFlow({ participation }: { participation: Participation }) {
  const { t, money } = useFormat();
  const queryClient = useQueryClient();
  const now = useNow(1_000);
  const [sheetOpen, setSheetOpen] = useState(false);
  const [providerPaymentId, setProviderPaymentId] = useState<string | null>(null);
  const payment = participation.payment;

  const store = (next: Participation) => {
    queryClient.setQueryData(queryKeys.participation(next.id), next);
    void queryClient.invalidateQueries({ queryKey: queryKeys.participations });
    void queryClient.invalidateQueries({ queryKey: queryKeys.progress(next.campaign.id) });
  };

  const sync = useMutation({
    mutationFn: (args: { paymentId: string; providerPaymentId: string }) =>
      api.syncPayment(args.paymentId, args.providerPaymentId),
    onSuccess: store,
  });

  const retry = useMutation({
    mutationFn: () => api.retryParticipationPayment(participation.id),
    onSuccess: (nextPayment) => {
      setProviderPaymentId(null);
      sync.reset();
      store({ ...participation, payment: nextPayment });
      if (nextPayment.checkout) setSheetOpen(true);
    },
  });

  const onSheetResult = (result: PaymentSheetResult) => {
    setSheetOpen(false);
    if (result.kind === 'completed' && payment) {
      setProviderPaymentId(result.providerPaymentId);
      sync.mutate({ paymentId: payment.id, providerPaymentId: result.providerPaymentId });
    }
  };

  const view = payView(participation, {
    now,
    syncing: sync.isPending,
    returnedFromProvider: providerPaymentId !== null,
  });
  const reservationLeft = remainingUntil(participation.reservation_expires_at, now);
  const rejection = payment?.rejection_reason
    ? t(`pay.rejection_${payment.rejection_reason}`)
    : null;

  const summary = (
    <Card style={styles.gap}>
      <Text weight="semibold">{participation.campaign.title}</Text>
      <DetailRow label={t('campaign.option')} value={participation.product_variant.name} />
      <DetailRow label={t('campaign.quantity')} value={String(participation.quantity)} />
      <DetailRow label={t('joins.holdUnitPrice')} value={money(participation.hold_unit_price)} />
      <Divider />
      <DetailRow
        label={t('checkout.holdAmount')}
        value={money(participation.hold_amount)}
        emphasis
      />
      <Text variant="small" color="textMuted">
        {t('checkout.maxCharge')}
      </Text>
    </Card>
  );

  const goToJoin = () =>
    router.replace({ pathname: '/joins/[id]', params: { id: participation.id } });
  const goToDeal = () =>
    router.replace({ pathname: '/campaign/[id]', params: { id: participation.campaign.id } });

  let body: ReactNode;
  let footer: ReactNode;

  switch (view) {
    case 'confirming':
      body = (
        <View style={styles.center}>
          <ActivityIndicator size="large" color={colors.primary} />
          <Text color="textMuted">{t('pay.confirming')}</Text>
        </View>
      );
      break;
    case 'ready':
      body = (
        <>
          <Notice
            tone="info"
            icon="time-outline"
            message={t('pay.reservedFor', { time: clock(reservationLeft) })}
          />
          {summary}
          {sync.error ? <Notice tone="danger" message={errorMessage(sync.error)} /> : null}
        </>
      );
      footer = (
        <Button
          testID="pay-button"
          icon="card-outline"
          title={`${t('pay.payWithCard')} · ${money(participation.hold_amount)}`}
          onPress={() => setSheetOpen(true)}
        />
      );
      break;
    case 'success':
      body = (
        <Outcome
          icon="checkmark-circle"
          color="success"
          title={t('pay.successTitle')}
          body={t('pay.successBody', { amount: money(participation.hold_amount) })}
        />
      );
      footer = (
        <>
          <Button testID="view-join" title={t('pay.viewJoin')} onPress={goToJoin} />
          <Button
            variant="ghost"
            title={t('pay.backToDeals')}
            onPress={() => router.dismissTo('/')}
          />
        </>
      );
      break;
    case 'declined':
      body = (
        <>
          <Outcome
            icon="close-circle"
            color="danger"
            title={t('pay.declinedTitle')}
            body={rejection ?? t('pay.declinedBody')}
          />
          <Notice
            tone="info"
            icon="time-outline"
            message={t('pay.reservedFor', { time: clock(reservationLeft) })}
          />
          {retry.error ? <Notice tone="danger" message={errorMessage(retry.error)} /> : null}
        </>
      );
      footer = (
        <Button
          testID="retry-payment"
          title={t('pay.tryAnotherCard')}
          loading={retry.isPending}
          onPress={() => retry.mutate()}
        />
      );
      break;
    case 'pending':
      body = (
        <>
          <Outcome
            icon="hourglass-outline"
            color="warning"
            title={t('pay.pendingTitle')}
            body={t('pay.pendingBody')}
          />
          {sync.error ? <Notice tone="danger" message={errorMessage(sync.error)} /> : null}
        </>
      );
      footer = (
        <Button
          title={t('pay.checkAgain')}
          onPress={() => {
            if (payment && providerPaymentId) {
              sync.mutate({ paymentId: payment.id, providerPaymentId });
            } else {
              void queryClient.invalidateQueries({
                queryKey: queryKeys.participation(participation.id),
              });
            }
          }}
        />
      );
      break;
    case 'failed':
      body = (
        <Outcome
          icon="close-circle"
          color="danger"
          title={t('pay.failedTitle')}
          body={rejection ?? t('pay.failedBody')}
        />
      );
      footer = <Button variant="secondary" title={t('pay.startAgain')} onPress={goToDeal} />;
      break;
    case 'expired':
      body = (
        <Outcome
          icon="time-outline"
          color="textMuted"
          title={t('joins.status_reservation_expired')}
          body={t('pay.reservationExpired')}
        />
      );
      footer = <Button variant="secondary" title={t('pay.startAgain')} onPress={goToDeal} />;
      break;
    default:
      body = (
        <Outcome
          icon="information-circle-outline"
          color="textMuted"
          title={t(`joins.status_${participation.status}`)}
          body={t(`joins.help_${participation.status}`)}
        />
      );
      footer = <Button title={t('pay.viewJoin')} onPress={goToJoin} />;
  }

  return (
    <Screen footer={footer}>
      {body}
      {view !== 'ready' && view !== 'confirming' ? summary : null}
      {payment?.checkout ? (
        <PaymentSheet
          visible={sheetOpen}
          paymentId={payment.id}
          checkout={payment.checkout}
          onResult={onSheetResult}
        />
      ) : null}
    </Screen>
  );
}

function Outcome({
  icon,
  color,
  title,
  body,
}: {
  icon: IconName;
  color: ColorName;
  title: string;
  body: string;
}) {
  return (
    <View style={styles.outcome} testID="pay-outcome">
      <Icon name={icon} size={56} color={color} />
      <Text variant="title" align="center">
        {title}
      </Text>
      <Text color="textMuted" align="center">
        {body}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  gap: {
    gap: space.sm,
  },
  center: {
    alignItems: 'center',
    justifyContent: 'center',
    gap: space.md,
    paddingVertical: space.xxl,
  },
  outcome: {
    alignItems: 'center',
    gap: space.sm,
    paddingVertical: space.xl,
  },
});
