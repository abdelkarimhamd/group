import { StyleSheet, View } from 'react-native';

import type { PriceTier } from '@/api/types';
import { useFormat } from '@/i18n/useFormat';
import { isTierReached } from '@/lib/campaign';
import { colors, radius, space } from '@/theme/tokens';

import { Icon } from './Icon';
import { Pill } from './Pill';
import { Text } from './Text';

/** Price tiers, lowest quantity first, with the current tier highlighted. */
export function TierLadder({ tiers, committedQty }: { tiers: PriceTier[]; committedQty: number }) {
  const { t, money } = useFormat();
  return (
    <View style={styles.list} testID="tier-ladder">
      {tiers.map((tier) => {
        const reached = isTierReached(tier, committedQty);
        return (
          <View
            key={`${tier.min_qty}-${tier.max_qty}`}
            style={[styles.row, tier.is_current && styles.current]}>
            <View style={styles.qty}>
              <Icon
                name={reached ? 'checkmark-circle' : 'ellipse-outline'}
                size={18}
                color={reached ? 'success' : 'textSubtle'}
              />
              <Text variant="body" color={tier.is_current ? 'text' : 'textMuted'}>
                {t('campaign.tierRange', { min: tier.min_qty, max: tier.max_qty })}
              </Text>
              {tier.is_current ? <Pill tone="primary" label={t('campaign.tierCurrent')} /> : null}
            </View>
            <Text variant="heading" color={tier.is_current ? 'primary' : 'text'}>
              {money(tier.unit_price, { compact: true })}
            </Text>
          </View>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  list: {
    gap: space.xs,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingVertical: space.sm,
    paddingHorizontal: space.md,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: 'transparent',
  },
  current: {
    backgroundColor: colors.primarySoft,
    borderColor: colors.primary,
  },
  qty: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.sm,
    flexShrink: 1,
  },
});
