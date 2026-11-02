import { useMutation, useQueryClient } from '@tanstack/react-query';
import * as Crypto from 'expo-crypto';
import { Redirect, router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { Pressable, StyleSheet, View } from 'react-native';

import { api } from '@/api/endpoints';
import { isApiError } from '@/api/errors';
import { queryKeys, useAddresses, useCampaign, useCampaignProgress, useMe } from '@/api/queries';
import type { Address, CampaignProgress, Money, User } from '@/api/types';
import { useAuth } from '@/auth/session';
import { Button } from '@/components/Button';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import { Notice } from '@/components/Notice';
import { DetailRow, Divider } from '@/components/Rows';
import { Screen } from '@/components/Screen';
import { ErrorState, LoadingState } from '@/components/States';
import { Text } from '@/components/Text';
import { useFormat } from '@/i18n/useFormat';
import { addressLine, needsPrivacyConsent } from '@/lib/account';
import { latestProgress } from '@/lib/campaign';
import { multiplyMinor } from '@/lib/money';
import { colors, radius, space } from '@/theme/tokens';

export default function CheckoutScreen() {
  const params = useLocalSearchParams<{ campaignId: string; variant?: string; qty?: string }>();
  const { isSignedIn } = useAuth();
  const campaign = useCampaign(params.campaignId);
  const addresses = useAddresses(isSignedIn);
  const me = useMe(isSignedIn);

  if (!isSignedIn) {
    const next = `/checkout/${params.campaignId}?variant=${params.variant ?? ''}&qty=${params.qty ?? '1'}`;
    return <Redirect href={{ pathname: '/sign-in', params: { next } }} />;
  }
  const failed = [campaign, addresses, me].find((q) => q.isError);
  if (failed) return <ErrorState error={failed.error} onRetry={() => void failed.refetch()} />;
  if (!campaign.data || !addresses.data || !me.data) return <LoadingState />;

  const variant =
    campaign.data.variants.find((v) => v.id === params.variant) ?? campaign.data.variants[0];
  const quantity = Math.max(1, Number.parseInt(params.qty ?? '1', 10) || 1);
  if (!variant) return <ErrorState error={null} />;

  return (
    <CheckoutForm
      campaignId={campaign.data.id}
      title={campaign.data.title}
      variant={{ id: variant.id, name: variant.name }}
      quantity={quantity}
      campaignProgress={campaign.data.progress}
      cancellationRule={campaign.data.cancellation_rule}
      addresses={addresses.data}
      user={me.data}
    />
  );
}

function CheckoutForm({
  campaignId,
  title,
  variant,
  quantity,
  campaignProgress,
  cancellationRule,
  addresses,
  user,
}: {
  campaignId: string;
  title: string;
  variant: { id: string; name: string };
  quantity: number;
  campaignProgress: CampaignProgress;
  cancellationRule: string;
  addresses: Address[];
  user: User;
}) {
  const { t, money, minor } = useFormat();
  const queryClient = useQueryClient();
  const live = useCampaignProgress(campaignId, true);
  const progress = latestProgress(campaignProgress, live.data);

  // One ID per join attempt; reused when a retry follows a network failure, so the API
  // returns the original participation instead of joining twice.
  const [clientRequestId, setClientRequestId] = useState(() => Crypto.randomUUID());
  const [addressId, setAddressId] = useState<string | null>(
    (addresses.find((a) => a.is_default) ?? addresses[0])?.id ?? null,
  );
  const [confirmedPrice, setConfirmedPrice] = useState<Money | null>(null);
  const shownPrice = confirmedPrice ?? progress.current_unit_price;
  const holdMinor = multiplyMinor(shownPrice.amount_minor, quantity);
  const consentNeeded = needsPrivacyConsent(user);

  const join = useMutation({
    mutationFn: () =>
      api.joinCampaign(campaignId, {
        client_request_id: clientRequestId,
        product_variant_id: variant.id,
        quantity,
        shown_unit_price_halalas: shownPrice.amount_minor,
        delivery_address_id: addressId ?? '',
      }),
    onSuccess: (participation) => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.participations });
      void queryClient.invalidateQueries({ queryKey: queryKeys.progress(campaignId) });
      queryClient.setQueryData(queryKeys.participation(participation.id), participation);
      router.replace({
        pathname: '/pay/[participationId]',
        params: { participationId: participation.id },
      });
    },
    onError: (error) => {
      if (!isApiError(error) || error.isNetworkError || error.status >= 500) return;
      // The API answered, so nothing was created: the next attempt is a new join.
      setClientRequestId(Crypto.randomUUID());
      if (error.code === 'price_changed') {
        const body = error.body as { current_unit_price?: Money } | undefined;
        if (body?.current_unit_price) setConfirmedPrice(body.current_unit_price);
        void queryClient.invalidateQueries({ queryKey: queryKeys.campaign(campaignId) });
      }
      if (error.code === 'privacy_consent_required') {
        router.push('/privacy');
      }
    },
  });

  const submit = () => {
    if (consentNeeded) {
      router.push('/privacy');
      return;
    }
    join.mutate();
  };

  const error = join.error;
  const errorNotice = (() => {
    if (!error) return null;
    if (!isApiError(error)) return t('errors.generic');
    switch (error.code) {
      case 'price_changed':
        return t('checkout.priceChangedBody', { price: money(shownPrice) });
      case 'sold_out': {
        const remaining =
          (error.body as { remaining_qty?: number } | undefined)?.remaining_qty ?? 0;
        return t('checkout.soldOutBody', { n: remaining });
      }
      case 'campaign_not_joinable':
        return t('checkout.notJoinable');
      default:
        return error.message;
    }
  })();
  const blocked =
    isApiError(error) && (error.code === 'sold_out' || error.code === 'campaign_not_joinable');

  return (
    <Screen
      footer={
        blocked ? (
          <Button variant="secondary" title={t('pay.startAgain')} onPress={() => router.back()} />
        ) : (
          <Button
            testID="confirm-join"
            title={
              isApiError(error) && error.code === 'price_changed'
                ? t('common.continue')
                : t('checkout.confirm')
            }
            loading={join.isPending}
            disabled={addressId === null}
            onPress={submit}
          />
        )
      }>
      {errorNotice ? (
        <Notice
          tone={isApiError(error) && error.code === 'price_changed' ? 'warning' : 'danger'}
          message={errorNotice}
        />
      ) : null}

      <Card style={styles.gap}>
        <Text variant="heading">{t('checkout.deliverTo')}</Text>
        {addresses.length === 0 ? (
          <Text color="textMuted">{t('checkout.noAddress')}</Text>
        ) : (
          addresses.map((address) => (
            <AddressOption
              key={address.id}
              address={address}
              selected={address.id === addressId}
              onPress={() => setAddressId(address.id)}
            />
          ))
        )}
        <Button
          variant="secondary"
          compact
          icon="add"
          title={t('checkout.addAddress')}
          onPress={() => router.push('/addresses/edit')}
        />
      </Card>

      <Card style={styles.gap}>
        <Text variant="heading">{t('checkout.summary')}</Text>
        <Text weight="medium">{title}</Text>
        <DetailRow label={t('campaign.option')} value={variant.name} />
        <DetailRow label={t('campaign.quantity')} value={String(quantity)} />
        <DetailRow label={t('checkout.unitPriceNow')} value={money(shownPrice)} />
        <Divider />
        <DetailRow
          testID="checkout-hold"
          label={t('checkout.holdAmount')}
          value={minor(holdMinor)}
          emphasis
        />
        <Text variant="small" color="textMuted">
          {t('checkout.maxCharge')}
        </Text>
        <Text variant="small" color="textMuted">
          {t(`campaign.cancelRule_${cancellationRule}`)}
        </Text>
      </Card>

      {consentNeeded ? (
        <Notice
          tone="info"
          icon="shield-checkmark-outline"
          message={user.privacy_consent ? t('privacy.updated') : t('privacy.body')}
        />
      ) : null}
    </Screen>
  );
}

function AddressOption({
  address,
  selected,
  onPress,
}: {
  address: Address;
  selected: boolean;
  onPress: () => void;
}) {
  const { locale } = useFormat();
  return (
    <Pressable
      accessibilityRole="radio"
      accessibilityState={{ checked: selected }}
      onPress={onPress}
      style={[styles.address, selected && styles.addressSelected]}>
      <Icon
        name={selected ? 'radio-button-on' : 'radio-button-off'}
        size={20}
        color={selected ? 'primary' : 'textSubtle'}
      />
      <View style={styles.flex}>
        <Text weight="medium">{address.recipient_name}</Text>
        <Text variant="small" color="textMuted">
          {addressLine(address, locale)}
        </Text>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  gap: {
    gap: space.md,
  },
  flex: {
    flex: 1,
  },
  address: {
    flexDirection: 'row',
    gap: space.md,
    alignItems: 'flex-start',
    padding: space.md,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
  },
  addressSelected: {
    borderColor: colors.primary,
    backgroundColor: colors.primarySoft,
  },
});
