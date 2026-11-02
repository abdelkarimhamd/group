import { useMutation, useQueryClient } from '@tanstack/react-query';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, Switch, View } from 'react-native';

import { api } from '@/api/endpoints';
import { isApiError } from '@/api/errors';
import { queryKeys, useAddresses, useMe } from '@/api/queries';
import type { Address, User } from '@/api/types';
import { useAuth } from '@/auth/session';
import { Button } from '@/components/Button';
import { Notice } from '@/components/Notice';
import { Screen } from '@/components/Screen';
import { LoadingState } from '@/components/States';
import { Text } from '@/components/Text';
import { TextField } from '@/components/TextField';
import { useFormat } from '@/i18n/useFormat';
import {
  addressToForm,
  EMPTY_ADDRESS,
  validateAddress,
  type AddressField,
  type AddressForm,
} from '@/lib/address';
import { colors, space } from '@/theme/tokens';

export default function EditAddressScreen() {
  const { id } = useLocalSearchParams<{ id?: string }>();
  const { isSignedIn } = useAuth();
  const addresses = useAddresses(isSignedIn);
  const me = useMe(isSignedIn);
  if (!addresses.data || !me.data) return <LoadingState />;
  const existing = id ? addresses.data.find((a) => a.id === id) : undefined;
  return <AddressEditor existing={existing} user={me.data} isFirst={addresses.data.length === 0} />;
}

function AddressEditor({
  existing,
  user,
  isFirst,
}: {
  existing: Address | undefined;
  user: User;
  isFirst: boolean;
}) {
  const { t } = useFormat();
  const queryClient = useQueryClient();
  const [form, setForm] = useState<AddressForm>(() =>
    existing
      ? addressToForm(existing)
      : {
          ...EMPTY_ADDRESS,
          recipient_name: user.name ?? '',
          recipient_phone: user.phone.replace(/^\+966/, '0'),
          is_default: isFirst,
        },
  );
  const [showErrors, setShowErrors] = useState(false);
  const { input, errors } = validateAddress(form);

  const save = useMutation({
    mutationFn: () => {
      if (!input) throw new Error('invalid');
      return existing ? api.updateAddress(existing.id, input) : api.createAddress(input);
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.addresses });
      router.back();
    },
  });

  const set = (field: keyof AddressForm) => (value: string) =>
    setForm((f) => ({ ...f, [field]: value }));

  const errorFor = (field: AddressField) => {
    const serverError = isApiError(save.error) ? save.error.fieldError(field) : undefined;
    if (serverError) return serverError;
    return showErrors && errors[field] ? t(`validation.${errors[field]}`) : undefined;
  };

  const field = (
    name: AddressField,
    extra: Partial<React.ComponentProps<typeof TextField>> = {},
  ) => (
    <TextField
      testID={`address-${name}`}
      label={t(`addresses.${name}`)}
      value={form[name]}
      onChangeText={set(name)}
      error={errorFor(name)}
      {...extra}
    />
  );

  return (
    <Screen
      footer={
        <Button
          testID="save-address"
          title={t('common.save')}
          loading={save.isPending}
          onPress={() => {
            setShowErrors(true);
            if (input) save.mutate();
          }}
        />
      }>
      <Stack.Screen
        options={{ title: existing ? t('addresses.editTitle') : t('addresses.newTitle') }}
      />
      <Notice tone="info" icon="location-outline" message={t('addresses.nationalAddressNote')} />
      {save.error && !isApiError(save.error) ? (
        <Notice tone="danger" message={t('errors.generic')} />
      ) : null}
      {isApiError(save.error) && Object.keys(save.error.fieldErrors).length === 0 ? (
        <Notice tone="danger" message={save.error.message} />
      ) : null}
      {field('recipient_name', { autoComplete: 'name', maxLength: 100 })}
      {field('recipient_phone', {
        keyboardType: 'phone-pad',
        autoComplete: 'tel',
        ltr: true,
        maxLength: 16,
      })}
      {field('city', { maxLength: 100 })}
      {field('district', { maxLength: 100 })}
      {field('street', { maxLength: 150 })}
      <View style={styles.pair}>
        <View style={styles.flex}>
          {field('building_no', {
            keyboardType: 'number-pad',
            maxLength: 4,
            ltr: true,
            hint: t('addresses.hintFourDigits'),
          })}
        </View>
        <View style={styles.flex}>
          {field('postal_code', {
            keyboardType: 'number-pad',
            maxLength: 5,
            ltr: true,
            hint: t('addresses.hintFiveDigits'),
          })}
        </View>
      </View>
      {field('additional_no', {
        keyboardType: 'number-pad',
        maxLength: 4,
        ltr: true,
        optional: true,
        hint: t('addresses.hintFourDigits'),
      })}
      {field('short_address', {
        autoCapitalize: 'characters',
        maxLength: 8,
        ltr: true,
        optional: true,
        hint: t('addresses.hintShortAddress'),
      })}
      <View style={styles.switchRow}>
        <Text style={styles.flex}>{t('addresses.makeDefault')}</Text>
        <Switch
          testID="address-default"
          // The API moves the default only when another address takes it, so the current
          // default cannot be switched off here.
          disabled={existing?.is_default === true}
          value={form.is_default}
          onValueChange={(value) => setForm((f) => ({ ...f, is_default: value }))}
          trackColor={{ true: colors.primary, false: colors.border }}
          thumbColor={colors.surface}
        />
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
  },
  pair: {
    flexDirection: 'row',
    gap: space.md,
  },
  switchRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.md,
  },
});
