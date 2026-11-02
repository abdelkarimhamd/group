import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Redirect, router } from 'expo-router';
import { StyleSheet, View } from 'react-native';

import { api } from '@/api/endpoints';
import { errorMessage } from '@/api/errors';
import { queryKeys, useAddresses } from '@/api/queries';
import type { Address } from '@/api/types';
import { useAuth } from '@/auth/session';
import { Button } from '@/components/Button';
import { Card } from '@/components/Card';
import { Notice } from '@/components/Notice';
import { Pill } from '@/components/Pill';
import { Screen } from '@/components/Screen';
import { EmptyState, ErrorState, LoadingState } from '@/components/States';
import { Text } from '@/components/Text';
import { useFormat } from '@/i18n/useFormat';
import { addressLine } from '@/lib/account';
import { confirmAction } from '@/lib/confirm';
import { space } from '@/theme/tokens';

export default function AddressesScreen() {
  const { t } = useFormat();
  const { isSignedIn } = useAuth();
  const addresses = useAddresses(isSignedIn);
  const queryClient = useQueryClient();

  const remove = useMutation({
    mutationFn: (id: string) => api.deleteAddress(id),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: queryKeys.addresses }),
  });

  if (!isSignedIn) return <Redirect href="/sign-in" />;

  const askDelete = async (address: Address) => {
    const ok = await confirmAction({
      title: t('addresses.deleteConfirm'),
      message: t('addresses.deleteBody'),
      confirmText: t('common.delete'),
      cancelText: t('common.cancel'),
      destructive: true,
    });
    if (ok) remove.mutate(address.id);
  };

  return (
    <Screen
      refreshing={addresses.isRefetching}
      onRefresh={() => void addresses.refetch()}
      footer={
        <Button
          testID="add-address"
          icon="add"
          title={t('addresses.add')}
          onPress={() => router.push('/addresses/edit')}
        />
      }>
      {remove.error ? <Notice tone="danger" message={errorMessage(remove.error)} /> : null}
      {addresses.isPending ? (
        <LoadingState />
      ) : addresses.isError ? (
        <ErrorState error={addresses.error} onRetry={() => void addresses.refetch()} />
      ) : addresses.data.length === 0 ? (
        <EmptyState icon="location-outline" message={t('addresses.empty')} />
      ) : (
        addresses.data.map((address) => (
          <AddressCard
            key={address.id}
            address={address}
            onDelete={() => void askDelete(address)}
          />
        ))
      )}
    </Screen>
  );
}

function AddressCard({ address, onDelete }: { address: Address; onDelete: () => void }) {
  const { t, locale, phone } = useFormat();
  return (
    <Card style={styles.card}>
      <View style={styles.row}>
        <Text weight="semibold" style={styles.flex}>
          {address.recipient_name}
        </Text>
        {address.is_default ? <Pill tone="primary" label={t('addresses.default')} /> : null}
      </View>
      <Text variant="small" color="textMuted">
        {addressLine(address, locale)}
      </Text>
      <Text variant="small" color="textMuted">
        {phone(address.recipient_phone)}
      </Text>
      <View style={styles.actions}>
        <Button
          variant="secondary"
          compact
          icon="create-outline"
          title={t('common.edit')}
          onPress={() => router.push({ pathname: '/addresses/edit', params: { id: address.id } })}
        />
        <Button
          variant="danger"
          compact
          icon="trash-outline"
          title={t('common.delete')}
          onPress={onDelete}
        />
      </View>
    </Card>
  );
}

const styles = StyleSheet.create({
  card: {
    gap: space.xs,
  },
  flex: {
    flex: 1,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.sm,
  },
  actions: {
    flexDirection: 'row',
    gap: space.sm,
    marginTop: space.sm,
  },
});
