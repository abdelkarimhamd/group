import { router } from 'expo-router';
import { FlatList, RefreshControl, StyleSheet, View } from 'react-native';

import { flattenPages, useParticipations } from '@/api/queries';
import { useAuth } from '@/auth/session';
import { ParticipationCard } from '@/components/ParticipationCard';
import { SignInPrompt } from '@/components/SignInPrompt';
import { EmptyState, ErrorState, LoadingState } from '@/components/States';
import { useFormat } from '@/i18n/useFormat';
import { colors, space } from '@/theme/tokens';

export default function JoinsScreen() {
  const { isSignedIn } = useAuth();
  if (!isSignedIn) return <SignInPrompt next="/joins" />;
  return <JoinsList />;
}

function JoinsList() {
  const { t } = useFormat();
  const joins = useParticipations();
  const items = flattenPages(joins.data);
  return (
    <FlatList
      style={styles.list}
      contentContainerStyle={styles.content}
      data={items}
      keyExtractor={(item) => item.id}
      renderItem={({ item }) => <ParticipationCard participation={item} />}
      ItemSeparatorComponent={() => <View style={{ height: space.md }} />}
      ListEmptyComponent={
        joins.isPending ? (
          <LoadingState />
        ) : joins.isError ? (
          <ErrorState error={joins.error} onRetry={() => void joins.refetch()} />
        ) : (
          <EmptyState
            icon="people-outline"
            message={t('joins.empty')}
            action={{ title: t('joins.browse'), onPress: () => router.navigate('/') }}
          />
        )
      }
      onEndReached={() => {
        if (joins.hasNextPage && !joins.isFetchingNextPage) void joins.fetchNextPage();
      }}
      onEndReachedThreshold={0.5}
      refreshControl={
        <RefreshControl
          refreshing={joins.isRefetching && !joins.isFetchingNextPage}
          onRefresh={() => void joins.refetch()}
          tintColor={colors.primary}
          colors={[colors.primary]}
        />
      }
    />
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
});
