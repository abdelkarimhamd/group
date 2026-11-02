import { StyleSheet, View } from 'react-native';

import type { CampaignProgress as Progress } from '@/api/types';
import { useFormat } from '@/i18n/useFormat';
import { isDealConfirmed, progressFractions } from '@/lib/campaign';
import { space } from '@/theme/tokens';

import { Pill } from './Pill';
import { ProgressBar } from './ProgressBar';
import { Text } from './Text';

/** "47 more needed" before the target; "Deal confirmed · 20 left" after it. */
export function CampaignProgress({
  progress,
  compact = false,
}: {
  progress: Progress;
  compact?: boolean;
}) {
  const { t, tp } = useFormat();
  const confirmed = isDealConfirmed(progress);
  const fractions = progressFractions(progress);
  const soldOut = progress.remaining_qty <= 0;

  return (
    <View style={styles.container} testID="campaign-progress">
      <View style={styles.row}>
        {confirmed ? (
          <Pill tone="success" icon="checkmark-circle" label={t('progress.dealConfirmed')} />
        ) : (
          <Text variant={compact ? 'small' : 'body'} weight="semibold" color="primary">
            {tp('progress.moreNeeded', progress.units_to_target)}
          </Text>
        )}
        <Text variant="small" color="textMuted">
          {soldOut
            ? t('progress.soldOut')
            : confirmed
              ? t('progress.left', { n: progress.remaining_qty })
              : t('progress.claimed', {
                  committed: progress.committed_qty,
                  target: progress.min_target_qty,
                })}
        </Text>
      </View>
      <ProgressBar
        value={fractions.committed}
        marker={fractions.target}
        confirmed={confirmed}
        height={compact ? 8 : 10}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    gap: space.sm,
  },
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: space.sm,
    flexWrap: 'wrap',
  },
});
