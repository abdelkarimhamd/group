import { Image } from 'expo-image';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Pressable, ScrollView, StyleSheet, useWindowDimensions, View } from 'react-native';

import { useCampaign, useCampaignProgress } from '@/api/queries';
import type { CampaignDetail, CampaignImage } from '@/api/types';
import { useAuth } from '@/auth/session';
import { Button } from '@/components/Button';
import { CampaignProgress } from '@/components/CampaignProgress';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import { Notice } from '@/components/Notice';
import { QuantityStepper } from '@/components/QuantityStepper';
import { Screen } from '@/components/Screen';
import { ErrorState, LoadingState } from '@/components/States';
import { CampaignStatusPill } from '@/components/StatusPills';
import { Text } from '@/components/Text';
import { TierLadder } from '@/components/TierLadder';
import { useNow } from '@/components/useNow';
import { useFormat } from '@/i18n/useFormat';
import {
  campaignPhase,
  isDealConfirmed,
  isJoinable,
  latestProgress,
  maxSelectableQuantity,
} from '@/lib/campaign';
import { multiplyMinor } from '@/lib/money';
import { colors, radius, space } from '@/theme/tokens';

export default function CampaignScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const campaign = useCampaign(id);

  if (campaign.isPending) return <LoadingState />;
  if (campaign.isError) {
    return <ErrorState error={campaign.error} onRetry={() => void campaign.refetch()} />;
  }
  return (
    <CampaignView
      campaign={campaign.data}
      onRefresh={() => void campaign.refetch()}
      refreshing={campaign.isRefetching}
    />
  );
}

function CampaignView({
  campaign,
  onRefresh,
  refreshing,
}: {
  campaign: CampaignDetail;
  onRefresh: () => void;
  refreshing: boolean;
}) {
  const { t, money, minor, date, timeLeft } = useFormat();
  const { isSignedIn } = useAuth();
  const now = useNow(30_000);
  const phase = campaignPhase(campaign.status);
  const live = useCampaignProgress(campaign.id, phase === 'open' || phase === 'closing');
  const progress = latestProgress(campaign.progress, live.data);
  const current = { ...campaign, progress, current_unit_price: progress.current_unit_price };

  const [variantId, setVariantId] = useState<string | null>(campaign.variants[0]?.id ?? null);
  const [quantity, setQuantity] = useState(1);
  const maxQty = maxSelectableQuantity(current);
  const qty = Math.min(Math.max(quantity, 1), Math.max(maxQty, 1));
  const holdMinor = multiplyMinor(progress.current_unit_price.amount_minor, qty);
  const joinable = isJoinable(current, now) && variantId !== null;

  const join = () => {
    if (!variantId) return;
    const target = {
      pathname: '/checkout/[campaignId]',
      params: { campaignId: campaign.id, variant: variantId, qty: String(qty) },
    } as const;
    if (isSignedIn) {
      router.push(target);
    } else {
      router.push({
        pathname: '/sign-in',
        params: { next: `/checkout/${campaign.id}?variant=${variantId}&qty=${qty}` },
      });
    }
  };

  return (
    <Screen
      onRefresh={onRefresh}
      refreshing={refreshing}
      contentStyle={styles.content}
      footer={
        phase === 'open' ? (
          joinable ? (
            <Button
              testID="join-button"
              title={t('campaign.join', { amount: minor(holdMinor) })}
              onPress={join}
            />
          ) : (
            <Button
              title={progress.remaining_qty <= 0 ? t('campaign.soldOut') : t('campaign.closed')}
              disabled
            />
          )
        ) : phase === 'upcoming' ? (
          <Button title={t('campaign.opensOn', { date: date(campaign.starts_at) })} disabled />
        ) : undefined
      }>
      <Gallery
        images={
          campaign.images.length > 0 ? campaign.images : campaign.image ? [campaign.image] : []
        }
      />

      <View style={styles.section}>
        <View style={styles.titleRow}>
          <CampaignStatusPill status={campaign.status} />
          <Text variant="small" color="textSubtle">
            {t('campaign.code', { code: campaign.code })}
          </Text>
        </View>
        <Text variant="display">{campaign.title}</Text>
        {campaign.category ? (
          <Text variant="small" color="textMuted">
            {campaign.category.name}
          </Text>
        ) : null}
      </View>

      <Outcome campaign={campaign} />

      <Card style={styles.cardGap}>
        <View style={styles.priceRow}>
          <View>
            <Text variant="small" color="textMuted">
              {campaign.final_unit_price ? t('campaign.finalPrice') : t('campaign.priceNow')}
            </Text>
            <Text variant="display" color="primary" testID="price-now">
              {money(campaign.final_unit_price ?? progress.current_unit_price, { compact: true })}
            </Text>
          </View>
          {!campaign.final_unit_price &&
          campaign.lowest_unit_price.amount_minor < progress.current_unit_price.amount_minor ? (
            <View style={styles.dropBadge}>
              <Icon name="trending-down" size={16} color="success" />
              <Text variant="small" weight="semibold" color="success">
                {t('campaign.canDropTo', {
                  price: money(campaign.lowest_unit_price, { compact: true }),
                })}
              </Text>
            </View>
          ) : null}
        </View>
        <Text variant="small" color="textMuted">
          {t('campaign.everyonePays')}
        </Text>
      </Card>

      {phase === 'open' || phase === 'closing' ? (
        <Card style={styles.cardGap}>
          <CampaignProgress progress={progress} />
          {isDealConfirmed(progress) ? (
            <Text variant="small" color="textMuted">
              {t('progress.dealConfirmedHint')}
            </Text>
          ) : null}
          <View style={styles.inline}>
            <Icon name="time-outline" size={16} color="textMuted" />
            <Text variant="small" color="textMuted">
              {phase === 'open'
                ? t('campaigns.endsIn', { time: timeLeft(campaign.deadline_at, now) })
                : t('campaign.closes', { date: date(campaign.deadline_at) })}
            </Text>
            {live.isSuccess ? (
              <View style={styles.liveDot} accessibilityLabel={t('progress.live')} />
            ) : null}
          </View>
          {campaign.original_deadline_at ? (
            <Text variant="small" color="textSubtle">
              {t('campaign.extendedFrom', { date: date(campaign.original_deadline_at) })}
            </Text>
          ) : null}
        </Card>
      ) : null}

      {campaign.tiers.length > 0 ? (
        <Card style={styles.cardGap}>
          <Text variant="heading">{t('campaign.tiersTitle')}</Text>
          <TierLadder tiers={campaign.tiers} committedQty={progress.committed_qty} />
        </Card>
      ) : null}

      {joinable || (phase === 'open' && campaign.variants.length > 0) ? (
        <Card style={styles.cardGap}>
          {campaign.variants.length > 1 ? (
            <View style={styles.cardGap}>
              <Text variant="heading">{t('campaign.option')}</Text>
              <View style={styles.variants}>
                {campaign.variants.map((v) => (
                  <Pressable
                    key={v.id}
                    accessibilityRole="radio"
                    accessibilityState={{ checked: v.id === variantId }}
                    onPress={() => setVariantId(v.id)}
                    style={[styles.variant, v.id === variantId && styles.variantSelected]}>
                    <Text
                      variant="small"
                      weight="medium"
                      color={v.id === variantId ? 'primary' : 'text'}>
                      {v.name}
                    </Text>
                  </Pressable>
                ))}
              </View>
            </View>
          ) : null}
          <View style={styles.qtyRow}>
            <View style={styles.flex}>
              <Text variant="heading">{t('campaign.quantity')}</Text>
              {campaign.per_customer_max_qty ? (
                <Text variant="small" color="textMuted">
                  {t('campaign.maxPerCustomer', { n: campaign.per_customer_max_qty })}
                </Text>
              ) : null}
            </View>
            <QuantityStepper value={qty} max={Math.max(maxQty, 1)} onChange={setQuantity} />
          </View>
          <View style={styles.holdBox}>
            <View style={styles.qtyRow}>
              <Text weight="medium" style={styles.flex}>
                {t('campaign.holdTitle')}
              </Text>
              <Text variant="title" testID="hold-amount">
                {minor(holdMinor)}
              </Text>
            </View>
            <Text variant="small" color="textMuted">
              {t('campaign.holdExplainer', { amount: minor(holdMinor) })}
            </Text>
            <Text variant="small" color="textMuted">
              {t(`campaign.cancelRule_${campaign.cancellation_rule}`)}
            </Text>
          </View>
        </Card>
      ) : null}

      {campaign.description ? (
        <Card style={styles.cardGap}>
          <Text variant="heading">{t('campaign.about')}</Text>
          <Text color="textMuted">{campaign.description}</Text>
        </Card>
      ) : null}
    </Screen>
  );
}

function Outcome({ campaign }: { campaign: CampaignDetail }) {
  const { t, money } = useFormat();
  switch (campaignPhase(campaign.status)) {
    case 'success':
      return campaign.final_unit_price ? (
        <Notice
          tone="success"
          message={t('campaign.outcomeSuccess', { price: money(campaign.final_unit_price) })}
        />
      ) : null;
    case 'failed':
      return <Notice tone="info" message={t('campaign.outcomeFailed')} />;
    case 'cancelled':
      return <Notice tone="info" message={t('campaign.outcomeCancelled')} />;
    case 'closing':
      return <Notice tone="warning" message={t('campaign.outcomeClosing')} />;
    default:
      return null;
  }
}

function Gallery({ images }: { images: CampaignImage[] }) {
  const { width } = useWindowDimensions();
  const [index, setIndex] = useState(0);
  const imageWidth = width - space.lg * 2;
  if (images.length === 0) {
    return (
      <View style={[styles.gallery, styles.galleryEmpty, { width: imageWidth }]}>
        <Icon name="cube-outline" size={48} color="textSubtle" />
      </View>
    );
  }
  return (
    <View>
      <ScrollView
        horizontal
        pagingEnabled
        showsHorizontalScrollIndicator={false}
        style={[styles.gallery, { width: imageWidth }]}
        onMomentumScrollEnd={(e) =>
          setIndex(Math.round(e.nativeEvent.contentOffset.x / Math.max(imageWidth, 1)))
        }>
        {images.map((img) => (
          <Image
            key={img.url}
            source={{ uri: img.url }}
            alt={img.alt ?? ''}
            style={{ width: imageWidth, height: imageWidth * 0.75 }}
            contentFit="cover"
            transition={150}
          />
        ))}
      </ScrollView>
      {images.length > 1 ? (
        <View style={styles.dots}>
          {images.map((img, i) => (
            <View key={img.url} style={[styles.dot, i === index && styles.dotActive]} />
          ))}
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  content: {
    paddingBottom: space.xxl,
  },
  flex: {
    flex: 1,
  },
  section: {
    gap: space.xs,
  },
  titleRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
  },
  cardGap: {
    gap: space.md,
  },
  priceRow: {
    flexDirection: 'row',
    alignItems: 'flex-end',
    justifyContent: 'space-between',
    flexWrap: 'wrap',
    gap: space.sm,
  },
  dropBadge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.xs,
    backgroundColor: colors.successSoft,
    paddingHorizontal: space.sm,
    paddingVertical: space.xs,
    borderRadius: radius.pill,
  },
  inline: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.xs,
  },
  liveDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: colors.success,
    marginStart: space.xs,
  },
  variants: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: space.sm,
  },
  variant: {
    paddingHorizontal: space.md,
    paddingVertical: space.sm,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  variantSelected: {
    borderColor: colors.primary,
    backgroundColor: colors.primarySoft,
  },
  qtyRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.md,
  },
  holdBox: {
    gap: space.sm,
    padding: space.md,
    borderRadius: radius.md,
    backgroundColor: colors.surfaceMuted,
  },
  gallery: {
    borderRadius: radius.lg,
    overflow: 'hidden',
    backgroundColor: colors.surfaceMuted,
  },
  galleryEmpty: {
    aspectRatio: 4 / 3,
    alignItems: 'center',
    justifyContent: 'center',
  },
  dots: {
    flexDirection: 'row',
    justifyContent: 'center',
    gap: space.xs,
    marginTop: space.sm,
  },
  dot: {
    width: 6,
    height: 6,
    borderRadius: 3,
    backgroundColor: colors.border,
  },
  dotActive: {
    backgroundColor: colors.primary,
    width: 16,
  },
});
