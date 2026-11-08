import { useMutation, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';

import { api } from '@/api/endpoints';
import { errorMessage } from '@/api/errors';
import { queryKeys, useMe } from '@/api/queries';
import { useAuth } from '@/auth/session';
import { Button } from '@/components/Button';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import { Notice } from '@/components/Notice';
import { Screen } from '@/components/Screen';
import { Text } from '@/components/Text';
import { config } from '@/config';
import { useFormat } from '@/i18n/useFormat';
import { needsPrivacyConsent } from '@/lib/account';
import { webPageUrl } from '@/lib/links';

/** PDPL consent, required before the first join and again whenever the policy changes. */
export default function PrivacyScreen() {
  const { t, date, locale } = useFormat();
  const queryClient = useQueryClient();
  const { isSignedIn } = useAuth();
  const me = useMe(isSignedIn);
  const user = me.data;
  const needed = user ? needsPrivacyConsent(user) : false;

  const accept = useMutation({
    mutationFn: () => api.acceptPrivacyPolicy(user!.current_privacy_policy_version),
    onSuccess: (updated) => {
      queryClient.setQueryData(queryKeys.me, updated);
      if (router.canGoBack()) router.back();
    },
  });

  return (
    <Screen
      footer={
        needed ? (
          <Button
            testID="accept-privacy"
            title={t('privacy.accept')}
            loading={accept.isPending}
            onPress={() => accept.mutate()}
          />
        ) : undefined
      }>
      <Icon name="shield-checkmark-outline" size={40} color="primary" />
      {user?.privacy_consent && needed ? (
        <Notice tone="info" message={t('privacy.updated')} />
      ) : null}
      <Card>
        <Text>{t('privacy.body')}</Text>
      </Card>
      {user ? (
        <Text variant="small" color="textMuted">
          {t('privacy.version', { version: user.current_privacy_policy_version })}
          {user.privacy_consent && !needed ? ` · ${date(user.privacy_consent.accepted_at)}` : ''}
        </Text>
      ) : null}
      <Button
        variant="secondary"
        icon="open-outline"
        title={t('privacy.readFull')}
        onPress={() =>
          void WebBrowser.openBrowserAsync(
            config.privacyPolicyUrl ?? webPageUrl(config.webUrl, locale, 'privacy'),
          )
        }
      />
      {accept.error ? <Notice tone="danger" message={errorMessage(accept.error)} /> : null}
    </Screen>
  );
}
