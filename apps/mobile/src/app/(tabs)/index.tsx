import { useState } from 'react';
import { FlatList, Pressable, RefreshControl, ScrollView, StyleSheet, View } from 'react-native';

import { flattenPages, useCampaigns, useCategories } from '@/api/queries';
import type { CampaignFilter } from '@/api/types';
import { CampaignCard } from '@/components/CampaignCard';
import { EmptyState, ErrorState, LoadingState } from '@/components/States';
import { Text } from '@/components/Text';
import { useFormat } from '@/i18n/useFormat';
import { colors, radius, space } from '@/theme/tokens';

const FILTERS: { value: CampaignFilter; label: string; empty: string }[] = [
  { value: 'open', label: 'campaigns.filterOpen', empty: 'campaigns.emptyOpen' },
  { value: 'upcoming', label: 'campaigns.filterUpcoming', empty: 'campaigns.emptyUpcoming' },
  { value: 'ended', label: 'campaigns.filterEnded', empty: 'campaigns.emptyEnded' },
];

export default function CampaignsScreen() {
  const { t } = useFormat();
  const [filter, setFilter] = useState<CampaignFilter>('open');
  const [category, setCategory] = useState<string | null>(null);
  const categories = useCategories();
  const campaigns = useCampaigns(filter, category);
  const items = flattenPages(campaigns.data);
  const current = FILTERS.find((f) => f.value === filter) ?? FILTERS[0];

  const header = (
    <View style={styles.header}>
      <Text color="textMuted">{t('campaigns.subtitle')}</Text>
      <View style={styles.segments} accessibilityRole="tablist">
        {FILTERS.map((f) => (
          <Segment
            key={f.value}
            label={t(f.label)}
            selected={filter === f.value}
            onPress={() => setFilter(f.value)}
          />
        ))}
      </View>
      {categories.data && categories.data.length > 0 ? (
        <ScrollView
          horizontal
          showsHorizontalScrollIndicator={false}
          contentContainerStyle={styles.chips}>
          <Chip
            label={t('campaigns.allCategories')}
            selected={category === null}
            onPress={() => setCategory(null)}
          />
          {categories.data
            .filter((c) => c.parent_id === null)
            .map((c) => (
              <Chip
                key={c.id}
                label={c.name}
                selected={category === c.slug}
                onPress={() => setCategory(c.slug)}
              />
            ))}
        </ScrollView>
      ) : null}
    </View>
  );

  return (
    <FlatList
      style={styles.list}
      contentContainerStyle={styles.content}
      data={items}
      keyExtractor={(item) => item.id}
      renderItem={({ item }) => <CampaignCard campaign={item} />}
      ItemSeparatorComponent={() => <View style={{ height: space.lg }} />}
      ListHeaderComponent={header}
      ListEmptyComponent={
        campaigns.isPending ? (
          <LoadingState />
        ) : campaigns.isError ? (
          <ErrorState error={campaigns.error} onRetry={() => void campaigns.refetch()} />
        ) : (
          <EmptyState icon="pricetags-outline" message={t(current.empty)} />
        )
      }
      onEndReached={() => {
        if (campaigns.hasNextPage && !campaigns.isFetchingNextPage) void campaigns.fetchNextPage();
      }}
      onEndReachedThreshold={0.5}
      refreshControl={
        <RefreshControl
          refreshing={campaigns.isRefetching && !campaigns.isFetchingNextPage}
          onRefresh={() => void campaigns.refetch()}
          tintColor={colors.primary}
          colors={[colors.primary]}
        />
      }
      ListFooterComponent={campaigns.isFetchingNextPage ? <LoadingState /> : undefined}
    />
  );
}

function Segment({
  label,
  selected,
  onPress,
}: {
  label: string;
  selected: boolean;
  onPress: () => void;
}) {
  return (
    <Pressable
      accessibilityRole="tab"
      accessibilityState={{ selected }}
      onPress={onPress}
      style={[styles.segment, selected && styles.segmentSelected]}>
      <Text
        variant="small"
        weight={selected ? 'semibold' : 'medium'}
        color={selected ? 'text' : 'textMuted'}
        align="center">
        {label}
      </Text>
    </Pressable>
  );
}

function Chip({
  label,
  selected,
  onPress,
}: {
  label: string;
  selected: boolean;
  onPress: () => void;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected }}
      onPress={onPress}
      style={[styles.chip, selected && styles.chipSelected]}>
      <Text variant="small" weight="medium" color={selected ? 'onPrimary' : 'text'}>
        {label}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  list: {
    flex: 1,
    backgroundColor: colors.background,
  },
  content: {
    padding: space.lg,
    paddingTop: space.xs,
    flexGrow: 1,
  },
  header: {
    gap: space.md,
    marginBottom: space.lg,
  },
  segments: {
    flexDirection: 'row',
    backgroundColor: colors.surfaceMuted,
    borderRadius: radius.md,
    padding: space.xs,
    gap: space.xs,
  },
  segment: {
    flex: 1,
    paddingVertical: space.sm,
    borderRadius: radius.sm,
  },
  segmentSelected: {
    backgroundColor: colors.surface,
  },
  chips: {
    gap: space.sm,
  },
  chip: {
    paddingHorizontal: space.md,
    paddingVertical: space.xs,
    borderRadius: radius.pill,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  chipSelected: {
    backgroundColor: colors.primary,
    borderColor: colors.primary,
  },
});
