import { Image } from 'expo-image';
import { Link, router } from 'expo-router';
import { FlatList, Pressable, RefreshControl, StyleSheet, View } from 'react-native';

import { flattenPages, useOrders } from '@/api/queries';
import type { Order } from '@/api/types';
import { useAuth } from '@/auth/session';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import { SignInPrompt } from '@/components/SignInPrompt';
import { EmptyState, ErrorState, LoadingState } from '@/components/States';
import { OrderStatusPill } from '@/components/StatusPills';
import { Text } from '@/components/Text';
import { useFormat } from '@/i18n/useFormat';
import { colors, radius, space } from '@/theme/tokens';

export default function OrdersScreen() {
  const { isSignedIn } = useAuth();
  if (!isSignedIn) return <SignInPrompt next="/orders" />;
  return <OrdersList />;
}

function OrdersList() {
  const { t } = useFormat();
  const orders = useOrders();
  const items = flattenPages(orders.data);
  return (
    <FlatList
      style={styles.list}
      contentContainerStyle={styles.content}
      data={items}
      keyExtractor={(item) => item.id}
      renderItem={({ item }) => <OrderCard order={item} />}
      ItemSeparatorComponent={() => <View style={{ height: space.md }} />}
      ListEmptyComponent={
        orders.isPending ? (
          <LoadingState />
        ) : orders.isError ? (
          <ErrorState error={orders.error} onRetry={() => void orders.refetch()} />
        ) : (
          <EmptyState
            icon="cube-outline"
            message={t('orders.empty')}
            action={{ title: t('joins.browse'), onPress: () => router.navigate('/') }}
          />
        )
      }
      onEndReached={() => {
        if (orders.hasNextPage && !orders.isFetchingNextPage) void orders.fetchNextPage();
      }}
      onEndReachedThreshold={0.5}
      refreshControl={
        <RefreshControl
          refreshing={orders.isRefetching && !orders.isFetchingNextPage}
          onRefresh={() => void orders.refetch()}
          tintColor={colors.primary}
          colors={[colors.primary]}
        />
      }
    />
  );
}

function OrderCard({ order }: { order: Order }) {
  const { t, money, date } = useFormat();
  return (
    <Link href={{ pathname: '/orders/[id]', params: { id: order.id } }} asChild>
      <Pressable accessibilityRole="link" testID={`order-${order.id}`}>
        {({ pressed }) => (
          <Card style={[styles.card, pressed && { opacity: 0.92 }]}>
            {order.campaign.image ? (
              <Image
                source={{ uri: order.campaign.image.url }}
                style={styles.thumb}
                contentFit="cover"
              />
            ) : (
              <View style={[styles.thumb, styles.thumbEmpty]}>
                <Icon name="cube-outline" size={24} color="textSubtle" />
              </View>
            )}
            <View style={styles.info}>
              <View style={styles.row}>
                <OrderStatusPill status={order.status} />
                <Text variant="tiny" color="textSubtle">
                  {order.order_number}
                </Text>
              </View>
              <Text weight="semibold" numberOfLines={2}>
                {order.campaign.title}
              </Text>
              <Text variant="small" color="textMuted">
                {order.product_variant.name} {t('joins.quantity', { n: order.quantity })} ·{' '}
                {money(order.total)}
              </Text>
              <Text variant="tiny" color="textSubtle">
                {date(order.created_at)}
              </Text>
            </View>
          </Card>
        )}
      </Pressable>
    </Link>
  );
}

const styles = StyleSheet.create({
  list: {
    flex: 1,
    backgroundColor: colors.background,
  },
  content: {
    padding: space.lg,
    flexGrow: 1,
  },
  card: {
    flexDirection: 'row',
    gap: space.md,
  },
  thumb: {
    width: 72,
    height: 72,
    borderRadius: radius.md,
    backgroundColor: colors.surfaceMuted,
  },
  thumbEmpty: {
    alignItems: 'center',
    justifyContent: 'center',
  },
  info: {
    flex: 1,
    gap: space.xs,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    gap: space.sm,
  },
});
