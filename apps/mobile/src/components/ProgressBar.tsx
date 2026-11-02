import { StyleSheet, View } from 'react-native';

import { useFormat } from '@/i18n/useFormat';
import { inset } from '@/theme/layout';
import { colors, radius } from '@/theme/tokens';

/**
 * A bar from 0 to the campaign maximum. `marker` draws the minimum target. Uses `start` so it
 * fills from the right in Arabic.
 */
export function ProgressBar({
  value,
  marker,
  confirmed,
  height = 10,
}: {
  value: number;
  marker?: number;
  confirmed?: boolean;
  height?: number;
}) {
  const { rtl } = useFormat();
  return (
    <View
      style={[styles.track, { height, borderRadius: height / 2 }]}
      accessibilityRole="progressbar"
      accessibilityValue={{ min: 0, max: 100, now: Math.round(value * 100) }}>
      <View
        style={[
          styles.fill,
          inset('start', 0, rtl),
          {
            width: `${value * 100}%`,
            borderRadius: height / 2,
            backgroundColor: confirmed ? colors.success : colors.primary,
          },
        ]}
      />
      {marker !== undefined && marker > 0 && marker < 1 ? (
        <View
          style={[styles.marker, inset('start', `${marker * 100}%`, rtl), { height: height + 6 }]}
        />
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  track: {
    width: '100%',
    backgroundColor: colors.track,
    justifyContent: 'center',
  },
  fill: {
    position: 'absolute',
    top: 0,
    bottom: 0,
  },
  marker: {
    position: 'absolute',
    width: 2,
    backgroundColor: colors.text,
    borderRadius: radius.sm,
  },
});
