import type { ComponentProps } from 'react';
import { StyleSheet, TextInput, View } from 'react-native';

import { useFormat } from '@/i18n/useFormat';
import { colors, fonts, radius, space, typeScale } from '@/theme/tokens';

import { Text } from './Text';

export type TextFieldProps = ComponentProps<typeof TextInput> & {
  label: string;
  hint?: string;
  error?: string;
  /** Digits, phone numbers and codes read left to right even in Arabic. */
  ltr?: boolean;
  optional?: boolean;
};

export function TextField({ label, hint, error, ltr, optional, style, ...rest }: TextFieldProps) {
  const { t } = useFormat();
  return (
    <View style={styles.field}>
      <View style={styles.labelRow}>
        <Text variant="small" weight="medium" color="textMuted">
          {label}
        </Text>
        {optional ? (
          <Text variant="tiny" color="textSubtle">
            {t('common.optional')}
          </Text>
        ) : null}
      </View>
      <TextInput
        accessibilityLabel={label}
        placeholderTextColor={colors.textSubtle}
        {...rest}
        style={[styles.input, ltr ? styles.ltr : null, error ? styles.inputError : null, style]}
      />
      {error ? (
        <Text variant="small" color="danger">
          {error}
        </Text>
      ) : hint ? (
        <Text variant="small" color="textSubtle">
          {hint}
        </Text>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  field: {
    gap: space.xs,
  },
  labelRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  input: {
    ...typeScale.body,
    fontFamily: fonts.regular,
    color: colors.text,
    minHeight: 50,
    paddingHorizontal: space.md,
    paddingVertical: space.sm,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.surface,
  },
  ltr: {
    writingDirection: 'ltr',
  },
  inputError: {
    borderColor: colors.danger,
  },
});
