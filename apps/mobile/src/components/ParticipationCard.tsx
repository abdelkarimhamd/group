import { Image } from 'expo-image';
import { Link } from 'expo-router';
import { Pressable, StyleSheet, View } from 'react-native';

import type { Participation } from '@/api/types';
import { useFormat } from '@/i18n/useFormat';
import { campaignPhase } from '@/lib/campaign';
import { colors, radius, space } from '@/theme/tokens';

import { CampaignProgress } from './CampaignProgress';
import { Card } from './Card';
import { Icon } from './Icon';
import { ParticipationStatusPill } from './StatusPills';
import { Text } from './Text';

export function ParticipationCard({ participation }: { participation: Participation }) {
  const { t, money } = useFormat();
  const campaignOpen = campaignPhase(participation.campaign.status) === 'open';
  const showProgress = campaignOpen && participation.status === 'authorized';
  return (
    <Link href={{ pathname: '/joins/[id]', params: { id: participation.id } }} asChild>
      <Pressable accessibilityRole="link" testID={`participation-${participation.id}`}>
        {({ pressed }) => (
          <Card style={[styles.card, pressed && { opacity: 0.92 }]}>
            <View style={styles.row}>
              {participation.campaign.image ? (
                <Image
                  source={{ uri: participation.campaign.image.url }}
                  style={styles.thumb}
                  contentFit="cover"
                />
              ) : (
                <View style={[styles.thumb, styles.thumbEmpty]}>
                  <Icon name="cube-outline" size={24} color="textSubtle" />
                </View>
              )}
              <View style={styles.info}>
                <ParticipationStatusPill status={participation.status} />
                <Text weight="semibold" numberOfLines={2}>
                  {participation.campaign.title}
                </Text>
                <Text variant="small" color="textMuted">
                  {participation.product_variant.name}{' '}
                  {t('joins.quantity', { n: participation.quantity })}
                </Text>
                <Text variant="small" weight="medium">
                  {participation.capture_amount
                    ? t('joins.charged', { amount: money(participation.capture_amount) })
                    : t('joins.held', { amount: money(participation.hold_amount) })}
                </Text>
              </View>
            </View>
            {showProgress ? (
              <CampaignProgress progress={participation.campaign.progress} compact />
            ) : null}
          </Card>
        )}
      </Pressable>
    </Link>
  );
}

const styles = StyleSheet.create({
  card: {
    gap: space.md,
  },
  row: {
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
});
