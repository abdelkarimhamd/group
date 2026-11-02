import { Stack, useLocalSearchParams } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { Fragment } from 'react';
import { StyleSheet, View } from 'react-native';

import { useOrder } from '@/api/queries';
import type { Order, Shipment } from '@/api/types';
import { Button } from '@/components/Button';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import { Notice } from '@/components/Notice';
import { DetailRow, Divider, NavRow } from '@/components/Rows';
import { Screen } from '@/components/Screen';
import { ErrorState, LoadingState } from '@/components/States';
import { OrderStatusPill } from '@/components/StatusPills';
import { Text } from '@/components/Text';
import { useFormat } from '@/i18n/useFormat';
import { addressLine } from '@/lib/account';
import { ORDER_STEPS, orderStepIndex } from '@/lib/order';
import { isPast } from '@/lib/time';
import { colors, space } from '@/theme/tokens';

export default function OrderScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const order = useOrder(id);
  if (order.isPending) return <LoadingState />;
  if (order.isError) return <ErrorState error={order.error} onRetry={() => void order.refetch()} />;
  return (
    <OrderDetail
      order={order.data}
      refreshing={order.isRefetching}
      onRefresh={() => void order.refetch()}
    />
  );
}

function OrderDetail({
  order,
  refreshing,
  onRefresh,
}: {
  order: Order;
  refreshing: boolean;
  onRefresh: () => void;
}) {
  const { t, money, date, locale, phone } = useFormat();
  const step = orderStepIndex(order.status);
  const refunded = order.payment.refunded_amount.amount_minor > 0;

  return (
    <Screen refreshing={refreshing} onRefresh={onRefresh}>
      <Stack.Screen options={{ title: t('orders.detailTitle', { number: order.order_number }) }} />

      <Card style={styles.gap}>
        <OrderStatusPill status={order.status} />
        <Text variant="heading">{order.campaign.title}</Text>
        <Text variant="small" color="textMuted">
          {order.product_variant.name} {t('joins.quantity', { n: order.quantity })}
        </Text>
        {step !== null ? <Steps current={step} /> : null}
        {order.delivered_at ? (
          <Text variant="small" color="textMuted">
            {t('orders.deliveredOn', { date: date(order.delivered_at) })}
          </Text>
        ) : null}
      </Card>

      {order.return_window_ends_at && !isPast(order.return_window_ends_at) ? (
        <Notice
          tone="info"
          message={t('orders.returnWindow', { date: date(order.return_window_ends_at) })}
        />
      ) : null}

      <Card style={styles.gap}>
        <Text variant="heading">{t('orders.tracking')}</Text>
        {order.shipment ? (
          <Tracking shipment={order.shipment} />
        ) : (
          <Text color="textMuted">{t('orders.noShipment')}</Text>
        )}
      </Card>

      <Card style={styles.gap}>
        <Text variant="heading">{t('orders.payment')}</Text>
        <DetailRow label={t('orders.unitPrice')} value={money(order.unit_price)} />
        <DetailRow label={t('orders.items')} value={money(order.goods_total)} />
        <DetailRow
          label={t('orders.delivery')}
          value={
            order.delivery_fee.amount_minor === 0 ? t('orders.free') : money(order.delivery_fee)
          }
        />
        <Divider />
        <DetailRow
          label={t('orders.total')}
          value={money(order.total)}
          emphasis
          testID="order-total"
        />
        <Text variant="small" color="textMuted">
          {t('orders.vatIncluded', { amount: money(order.vat) })}
        </Text>
        {refunded ? (
          <Text variant="small" color="success">
            {t('orders.refunded', { amount: money(order.payment.refunded_amount) })}
          </Text>
        ) : null}
      </Card>

      <Card style={styles.gap}>
        <Text variant="heading">{t('orders.shipTo')}</Text>
        <Text weight="medium">{order.shipping_address.recipient_name}</Text>
        <Text variant="small" color="textMuted">
          {addressLine(order.shipping_address, locale)}
        </Text>
        <Text variant="small" color="textMuted">
          {phone(order.shipping_address.recipient_phone)}
        </Text>
      </Card>

      {order.invoices.length > 0 ? (
        <Card padded={false}>
          <View style={styles.cardHeader}>
            <Text variant="heading">{t('orders.invoices')}</Text>
          </View>
          {order.invoices.map((invoice, i) => (
            <Fragment key={invoice.id}>
              {i > 0 ? <Divider /> : null}
              <NavRow
                icon="document-text-outline"
                title={t(`orders.invoice_${invoice.type}`)}
                subtitle={`${invoice.invoice_number} · ${money(invoice.total)}`}
                onPress={() => {
                  if (invoice.pdf_url) void WebBrowser.openBrowserAsync(invoice.pdf_url);
                }}
              />
            </Fragment>
          ))}
        </Card>
      ) : null}
    </Screen>
  );
}

function Steps({ current }: { current: number }) {
  const { t } = useFormat();
  return (
    <View style={styles.steps} testID="order-steps">
      {ORDER_STEPS.map((step, i) => {
        const reached = i <= current;
        return (
          <View key={step} style={styles.step}>
            <View style={styles.stepTrack}>
              <View
                style={[styles.stepLine, i === 0 && styles.hidden, reached && styles.lineReached]}
              />
              <View style={[styles.stepDot, reached && styles.dotReached]}>
                {reached ? <Icon name="checkmark" size={12} color="onPrimary" /> : null}
              </View>
              <View
                style={[
                  styles.stepLine,
                  i === ORDER_STEPS.length - 1 && styles.hidden,
                  i < current && styles.lineReached,
                ]}
              />
            </View>
            <Text variant="tiny" color={reached ? 'text' : 'textSubtle'} align="center">
              {t(`orders.status_${step}`)}
            </Text>
          </View>
        );
      })}
    </View>
  );
}

function Tracking({ shipment }: { shipment: Shipment }) {
  const { t, dateTime } = useFormat();
  const events = [...shipment.events].reverse();
  return (
    <View style={styles.gap}>
      <DetailRow
        label={t('orders.trackingNumber')}
        value={`${shipment.courier} · ${shipment.tracking_number}`}
      />
      <Text weight="medium">{t(`orders.shipment_${shipment.status}`)}</Text>
      {events.map((event, i) => (
        <View key={`${event.occurred_at}-${i}`} style={styles.event}>
          <View style={[styles.eventDot, i === 0 && styles.dotReached]} />
          <View style={styles.flex}>
            <Text variant="small" weight={i === 0 ? 'semibold' : 'regular'}>
              {event.description ?? t(`orders.shipment_${event.status}`)}
            </Text>
            <Text variant="tiny" color="textSubtle">
              {[dateTime(event.occurred_at), event.location].filter(Boolean).join(' · ')}
            </Text>
          </View>
        </View>
      ))}
      {shipment.tracking_url ? (
        <Button
          variant="secondary"
          compact
          icon="open-outline"
          title={t('orders.trackWith', { courier: shipment.courier })}
          onPress={() => void WebBrowser.openBrowserAsync(shipment.tracking_url!)}
        />
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  gap: {
    gap: space.sm,
  },
  flex: {
    flex: 1,
  },
  cardHeader: {
    paddingHorizontal: space.lg,
    paddingTop: space.lg,
    paddingBottom: space.xs,
  },
  steps: {
    flexDirection: 'row',
    marginTop: space.sm,
  },
  step: {
    flex: 1,
    alignItems: 'center',
    gap: space.xs,
  },
  stepTrack: {
    flexDirection: 'row',
    alignItems: 'center',
    alignSelf: 'stretch',
  },
  stepLine: {
    flex: 1,
    height: 2,
    backgroundColor: colors.border,
  },
  lineReached: {
    backgroundColor: colors.primary,
  },
  hidden: {
    opacity: 0,
  },
  stepDot: {
    width: 20,
    height: 20,
    borderRadius: 10,
    backgroundColor: colors.border,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dotReached: {
    backgroundColor: colors.primary,
  },
  event: {
    flexDirection: 'row',
    gap: space.md,
    alignItems: 'flex-start',
  },
  eventDot: {
    width: 10,
    height: 10,
    borderRadius: 5,
    marginTop: 6,
    backgroundColor: colors.border,
  },
});
