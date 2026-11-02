import { Image } from 'expo-image';
import { Link } from 'expo-router';
import { Pressable, StyleSheet, View } from 'react-native';

import type { CampaignSummary } from '@/api/types';
import { useFormat } from '@/i18n/useFormat';
import { campaignPhase } from '@/lib/campaign';
import { inset } from '@/theme/layout';
import { colors, radius, space } from '@/theme/tokens';

import { CampaignProgress } from './CampaignProgress';
import { Card } from './Card';
import { Icon } from './Icon';
import { CampaignStatusPill } from './StatusPills';
import { Text } from './Text';
import { useNow } from './useNow';

export function CampaignCard({ campaign }: { campaign: CampaignSummary }) {
  const { t, money, timeLeft, date, rtl } = useFormat();
  const now = useNow(60_000);
  const phase = campaignPhase(campaign.status);
  const priceDrops =
    campaign.lowest_unit_price.amount_minor < campaign.current_unit_price.amount_minor;

  return (
    <Link href={{ pathname: '/campaign/[id]', params: { id: campaign.id } }} asChild>
      <Pressable accessibilityRole="link" testID={`campaign-card-${campaign.id}`}>
        {({ pressed }) => (
          <Card padded={false} style={[styles.card, pressed && styles.pressed]}>
            <View style={styles.imageWrap}>
              {campaign.image ? (
                <Image
                  source={{ uri: campaign.image.url }}
                  alt={campaign.image.alt ?? campaign.title}
                  style={styles.image}
                  contentFit="cover"
                  transition={150}
                />
              ) : (
                <View style={[styles.image, styles.placeholder]}>
                  <Icon name="cube-outline" size={40} color="textSubtle" />
                </View>
              )}
              <View style={[styles.statusOverlay, inset('start', space.md, rtl)]}>
                <CampaignStatusPill status={campaign.status} />
              </View>
            </View>
            <View style={styles.body}>
              <Text variant="heading" numberOfLines={2}>
                {campaign.title}
              </Text>
              <View style={styles.priceRow}>
                <Text variant="title" color="text">
                  {money(campaign.current_unit_price, { compact: true })}
                </Text>
                {priceDrops ? (
                  <View style={styles.dropHint}>
                    <Icon name="trending-down" size={14} color="success" />
                    <Text variant="small" color="success" weight="medium">
                      {t('campaigns.downTo', {
                        price: money(campaign.lowest_unit_price, { compact: true }),
                      })}
                    </Text>
                  </View>
                ) : null}
              </View>
              {phase === 'open' || phase === 'closing' ? (
                <CampaignProgress progress={campaign.progress} compact />
              ) : null}
              <View style={styles.footer}>
                <Icon name="time-outline" size={14} color="textMuted" />
                <Text variant="small" color="textMuted">
                  {phase === 'upcoming'
                    ? t('campaigns.startsIn', { time: timeLeft(campaign.starts_at, now) })
                    : phase === 'open'
                      ? t('campaigns.endsIn', { time: timeLeft(campaign.deadline_at, now) })
                      : t('campaigns.closedOn', { date: date(campaign.deadline_at) })}
                </Text>
              </View>
            </View>
          </Card>
        )}
      </Pressable>
    </Link>
  );
}

const styles = StyleSheet.create({
  card: {
    overflow: 'hidden',
  },
  pressed: {
    opacity: 0.92,
  },
  imageWrap: {
    position: 'relative',
  },
  image: {
    width: '100%',
    aspectRatio: 16 / 10,
    backgroundColor: colors.surfaceMuted,
  },
  placeholder: {
    alignItems: 'center',
    justifyContent: 'center',
  },
  statusOverlay: {
    position: 'absolute',
    top: space.md,
  },
  body: {
    padding: space.lg,
    gap: space.md,
  },
  priceRow: {
    flexDirection: 'row',
    alignItems: 'center',
    flexWrap: 'wrap',
    gap: space.md,
  },
  dropHint: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.xs,
    backgroundColor: colors.successSoft,
    paddingHorizontal: space.sm,
    paddingVertical: space.xxs,
    borderRadius: radius.pill,
  },
  footer: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.xs,
  },
});
