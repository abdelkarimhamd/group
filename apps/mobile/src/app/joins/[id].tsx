import { useMutation, useQueryClient } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { StyleSheet, View } from 'react-native';

import { api } from '@/api/endpoints';
import { errorMessage } from '@/api/errors';
import { queryKeys, useParticipation } from '@/api/queries';
import type { Participation } from '@/api/types';
import { Button } from '@/components/Button';
import { CampaignProgress } from '@/components/CampaignProgress';
import { Card } from '@/components/Card';
import { Notice } from '@/components/Notice';
import { DetailRow, Divider } from '@/components/Rows';
import { Screen } from '@/components/Screen';
import { ErrorState, LoadingState } from '@/components/States';
import { CampaignStatusPill, ParticipationStatusPill } from '@/components/StatusPills';
import { Text } from '@/components/Text';
import { useNow } from '@/components/useNow';
import { clock, useFormat } from '@/i18n/useFormat';
import { campaignPhase } from '@/lib/campaign';
import { confirmAction } from '@/lib/confirm';
import { savingMinor } from '@/lib/money';
import { isPast, remainingUntil } from '@/lib/time';
import { space } from '@/theme/tokens';

const SCHEME_LABELS = { mada: 'mada', visa: 'Visa', mastercard: 'Mastercard' } as const;

export default function JoinDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const participation = useParticipation(id);
  if (participation.isPending) return <LoadingState />;
  if (participation.isError) {
    return <ErrorState error={participation.error} onRetry={() => void participation.refetch()} />;
  }
  return (
    <JoinDetail
      participation={participation.data}
      refreshing={participation.isRefetching}
      onRefresh={() => void participation.refetch()}
    />
  );
}

function JoinDetail({
  participation: p,
  refreshing,
  onRefresh,
}: {
  participation: Participation;
  refreshing: boolean;
  onRefresh: () => void;
}) {
  const { t, money, minor, date, dateTime } = useFormat();
  const queryClient = useQueryClient();
  const now = useNow(p.status === 'pending_payment' ? 1_000 : 30_000);

  const cancel = useMutation({
    mutationFn: () => api.cancelMyParticipation(p.id),
    onSuccess: (next) => {
      queryClient.setQueryData(queryKeys.participation(next.id), next);
      void queryClient.invalidateQueries({ queryKey: queryKeys.participations });
      void queryClient.invalidateQueries({ queryKey: queryKeys.progress(next.campaign.id) });
    },
  });

  const askCancel = async () => {
    const ok = await confirmAction({
      title: t('joins.cancelConfirmTitle'),
      message: t('joins.cancelConfirmBody'),
      confirmText: t('joins.cancel'),
      cancelText: t('common.back'),
      destructive: true,
    });
    if (ok) cancel.mutate();
  };

  const canPay = p.status === 'pending_payment' && !isPast(p.reservation_expires_at, now);
  const payment = p.payment;
  const releasedMinor = p.capture_amount
    ? savingMinor(p.hold_amount.amount_minor, p.capture_amount.amount_minor)
    : 0;

  return (
    <Screen
      refreshing={refreshing}
      onRefresh={onRefresh}
      footer={
        canPay || p.order_id || p.can_cancel ? (
          <>
            {canPay ? (
              <Button
                title={t('joins.completePayment')}
                onPress={() =>
                  router.push({
                    pathname: '/pay/[participationId]',
                    params: { participationId: p.id },
                  })
                }
              />
            ) : null}
            {p.order_id ? (
              <Button
                title={t('joins.viewOrder')}
                onPress={() =>
                  router.push({ pathname: '/orders/[id]', params: { id: p.order_id! } })
                }
              />
            ) : null}
            {p.can_cancel ? (
              <Button
                testID="cancel-join"
                variant="danger"
                title={t('joins.cancel')}
                loading={cancel.isPending}
                onPress={() => void askCancel()}
              />
            ) : null}
          </>
        ) : undefined
      }>
      {cancel.isSuccess ? <Notice tone="success" message={t('joins.cancelled')} /> : null}
      {cancel.error ? <Notice tone="danger" message={errorMessage(cancel.error)} /> : null}

      <Card style={styles.gap}>
        <ParticipationStatusPill status={p.status} />
        <Text color="textMuted">{t(`joins.help_${p.status}`)}</Text>
      </Card>

      {canPay ? (
        <Notice
          tone="warning"
          icon="time-outline"
          message={t('pay.reservedFor', {
            time: clock(remainingUntil(p.reservation_expires_at, now)),
          })}
        />
      ) : null}

      <Card style={styles.gap}>
        <View style={styles.row}>
          <CampaignStatusPill status={p.campaign.status} />
          <Button
            variant="ghost"
            compact
            title={t('joins.viewDeal')}
            onPress={() =>
              router.push({ pathname: '/campaign/[id]', params: { id: p.campaign.id } })
            }
          />
        </View>
        <Text variant="heading">{p.campaign.title}</Text>
        {campaignPhase(p.campaign.status) === 'open' ? (
          <CampaignProgress progress={p.campaign.progress} />
        ) : null}
      </Card>

      <Card style={styles.gap}>
        <DetailRow label={t('campaign.option')} value={p.product_variant.name} />
        <DetailRow label={t('campaign.quantity')} value={String(p.quantity)} />
        <DetailRow label={t('joins.holdUnitPrice')} value={money(p.hold_unit_price)} />
        <DetailRow label={t('joins.holdAmount')} value={money(p.hold_amount)} />
        {p.final_unit_price ? (
          <DetailRow
            label={t('joins.finalUnitPrice')}
            value={money(p.final_unit_price)}
            valueColor="success"
          />
        ) : null}
        {p.capture_amount ? (
          <>
            <Divider />
            <DetailRow label={t('joins.captureAmount')} value={money(p.capture_amount)} emphasis />
            {releasedMinor > 0 ? (
              <Text variant="small" color="success" testID="released-rest">
                {t('joins.releasedAmount', { amount: minor(releasedMinor) })}
              </Text>
            ) : null}
          </>
        ) : p.status === 'authorized' || p.status === 'pending_payment' ? (
          <Text variant="small" color="textMuted">
            {t('checkout.maxCharge')}
          </Text>
        ) : null}
      </Card>

      <Card style={styles.gap}>
        {payment ? (
          <>
            <Text variant="heading">{t('joins.paymentTitle')}</Text>
            <DetailRow
              label={t('joins.paymentStatus')}
              value={t(`pay.status_${payment.status}`)}
              testID="payment-status"
            />
            {payment.card_last4 ? (
              <DetailRow
                label={t('joins.card')}
                value={`${payment.scheme ? SCHEME_LABELS[payment.scheme] : ''} •••• ${payment.card_last4}`.trim()}
              />
            ) : null}
            {payment.status === 'authorized' ? (
              <DetailRow label={t('joins.holdExpires')} value={date(payment.expires_at)} />
            ) : null}
            {payment.rejection_reason ? (
              <Notice tone="danger" message={t(`pay.rejection_${payment.rejection_reason}`)} />
            ) : null}
          </>
        ) : null}
        <Text variant="small" color="textMuted">
          {t('joins.joinedOn', { date: dateTime(p.created_at) })}
        </Text>
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  gap: {
    gap: space.sm,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
});
