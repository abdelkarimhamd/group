import Ionicons from '@expo/vector-icons/Ionicons';
import type { ComponentProps } from 'react';

import { useFormat } from '@/i18n/useFormat';
import { colors, type ColorName } from '@/theme/tokens';

export type IconName = ComponentProps<typeof Ionicons>['name'];

/** Icons that point somewhere and must mirror in Arabic. */
const DIRECTIONAL = new Set<IconName>([
  'chevron-forward',
  'chevron-back',
  'arrow-forward',
  'arrow-back',
]);

export function Icon({
  name,
  size = 20,
  color = 'text',
}: {
  name: IconName;
  size?: number;
  color?: ColorName;
}) {
  const { rtl } = useFormat();
  return (
    <Ionicons
      name={name}
      size={size}
      color={colors[color]}
      style={DIRECTIONAL.has(name) && rtl ? { transform: [{ scaleX: -1 }] } : undefined}
      accessibilityElementsHidden
      importantForAccessibility="no"
    />
  );
}
