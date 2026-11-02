import { Text as RNText, type TextProps as RNTextProps } from 'react-native';

import {
  colors,
  fonts,
  typeScale,
  type ColorName,
  type FontWeightName,
  type TypeVariant,
} from '@/theme/tokens';

export type TextProps = RNTextProps & {
  variant?: TypeVariant;
  weight?: FontWeightName;
  color?: ColorName;
  align?: 'auto' | 'center';
};

const defaultWeight: Record<TypeVariant, FontWeightName> = {
  display: 'bold',
  title: 'bold',
  heading: 'semibold',
  body: 'regular',
  small: 'regular',
  tiny: 'medium',
};

/** Every piece of text goes through here so the font family and scale stay consistent. */
export function Text({
  variant = 'body',
  weight,
  color = 'text',
  align = 'auto',
  style,
  ...rest
}: TextProps) {
  return (
    <RNText
      {...rest}
      style={[
        typeScale[variant],
        {
          fontFamily: fonts[weight ?? defaultWeight[variant]],
          color: colors[color],
          textAlign: align === 'center' ? 'center' : undefined,
        },
        style,
      ]}
    />
  );
}
