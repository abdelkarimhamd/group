import Constants from 'expo-constants';
import { router } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { Pressable, StyleSheet, View } from 'react-native';

import { useMe } from '@/api/queries';
import { useAuth } from '@/auth/session';
import { Button } from '@/components/Button';
import { Card } from '@/components/Card';
import { Icon } from '@/components/Icon';
import { Notice } from '@/components/Notice';
import { Divider, NavRow } from '@/components/Rows';
import { Screen } from '@/components/Screen';
import { Text } from '@/components/Text';
import { config } from '@/config';
import { LOCALES, type Locale } from '@/i18n/locale';
import { useChangeLocale } from '@/i18n/useChangeLocale';
import { useFormat } from '@/i18n/useFormat';
import { confirmAction } from '@/lib/confirm';
import { webPageUrl, type WebPage } from '@/lib/links';
import { colors, radius, space } from '@/theme/tokens';

export default function AccountScreen() {
  const { t, phone, locale } = useFormat();
  const { isSignedIn, signOut } = useAuth();
  const me = useMe(isSignedIn);

  const openPage = (page: WebPage) =>
    WebBrowser.openBrowserAsync(
      page === 'privacy' && config.privacyPolicyUrl
        ? config.privacyPolicyUrl
        : webPageUrl(config.webUrl, locale, page),
    );

  const askSignOut = async () => {
    const ok = await confirmAction({
      title: t('account.signOut'),
      message: t('account.signOutConfirm'),
      confirmText: t('account.signOut'),
      cancelText: t('common.cancel'),
      destructive: true,
    });
    if (ok) await signOut();
  };

  return (
    <Screen edges={[]}>
      {isSignedIn ? (
        <Card style={styles.profile}>
          <View style={styles.avatar}>
            <Icon name="person" size={28} color="primary" />
          </View>
          <View style={styles.flex}>
            <Text variant="heading">{me.data?.name || t('account.addName')}</Text>
            {me.data ? (
              <Text variant="small" color="textMuted">
                {phone(me.data.phone)}
              </Text>
            ) : null}
          </View>
        </Card>
      ) : (
        <Card style={styles.signedOut}>
          <Text variant="heading">{t('account.signedOutTitle')}</Text>
          <Text color="textMuted">{t('account.signedOutBody')}</Text>
          <Button title={t('common.signIn')} onPress={() => router.push('/sign-in')} />
        </Card>
      )}

      {isSignedIn ? (
        <Card padded={false}>
          <NavRow
            icon="person-outline"
            title={t('account.profile')}
            onPress={() => router.push('/profile')}
          />
          <Divider />
          <NavRow
            icon="location-outline"
            title={t('account.addresses')}
            onPress={() => router.push('/addresses')}
          />
        </Card>
      ) : null}

      <LanguagePicker />

      <Card padded={false}>
        <View style={styles.cardHeader}>
          <Text variant="small" weight="semibold" color="textMuted">
            {t('account.help')}
          </Text>
        </View>
        <NavRow
          icon="help-circle-outline"
          title={t('account.howItWorks')}
          onPress={() => router.push('/how-it-works')}
        />
        <Divider />
        <NavRow
          icon="return-down-back-outline"
          title={t('account.returns')}
          onPress={() => void openPage('returns')}
        />
        <Divider />
        <NavRow
          icon="document-text-outline"
          title={t('account.terms')}
          onPress={() => void openPage('terms')}
        />
        <Divider />
        <NavRow
          icon="shield-checkmark-outline"
          title={t('account.privacy')}
          onPress={() => void openPage('privacy')}
        />
      </Card>

      {isSignedIn ? (
        <Card padded={false}>
          <NavRow
            testID="sign-out"
            icon="log-out-outline"
            title={t('account.signOut')}
            onPress={() => void askSignOut()}
          />
          <Divider />
          <NavRow
            testID="delete-account"
            icon="trash-outline"
            title={t('account.deleteAccount')}
            danger
            onPress={() => router.push('/delete-account')}
          />
        </Card>
      ) : null}

      {config.paymentProvider === 'fake' ? (
        <Notice tone="warning" icon="flask-outline" message={t('account.testPayments')} />
      ) : null}

      <Text variant="tiny" color="textSubtle" align="center">
        {t('account.version', { version: Constants.expoConfig?.version ?? '0.0.0' })}
      </Text>
    </Screen>
  );
}

function LanguagePicker() {
  const { t, locale } = useFormat();
  const changeLocale = useChangeLocale();

  const choose = async (next: Locale) => {
    if (next === locale) return;
    const ok = await confirmAction({
      title: t(`language.${next}`),
      message: t('account.languageRestart'),
      confirmText: t('common.continue'),
      cancelText: t('common.cancel'),
    });
    if (ok) await changeLocale(next);
  };

  return (
    <Card style={styles.gap}>
      <View style={styles.inline}>
        <Icon name="language-outline" size={22} color="textMuted" />
        <Text weight="medium">{t('account.language')}</Text>
      </View>
      <View style={styles.languages} accessibilityRole="radiogroup">
        {LOCALES.map((option) => (
          <Pressable
            key={option}
            testID={`language-${option}`}
            accessibilityRole="radio"
            accessibilityState={{ checked: option === locale }}
            onPress={() => void choose(option)}
            style={[styles.language, option === locale && styles.languageSelected]}>
            <Text weight="semibold" color={option === locale ? 'primary' : 'text'} align="center">
              {t(`language.${option}`)}
            </Text>
          </Pressable>
        ))}
      </View>
    </Card>
  );
}

const styles = StyleSheet.create({
  flex: {
    flex: 1,
  },
  gap: {
    gap: space.md,
  },
  inline: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.md,
  },
  profile: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: space.md,
  },
  avatar: {
    width: 56,
    height: 56,
    borderRadius: 28,
    backgroundColor: colors.primarySoft,
    alignItems: 'center',
    justifyContent: 'center',
  },
  signedOut: {
    gap: space.md,
  },
  cardHeader: {
    paddingHorizontal: space.lg,
    paddingTop: space.md,
  },
  languages: {
    flexDirection: 'row',
    gap: space.sm,
  },
  language: {
    flex: 1,
    paddingVertical: space.md,
    borderRadius: radius.md,
    borderWidth: 1,
    borderColor: colors.border,
  },
  languageSelected: {
    borderColor: colors.primary,
    backgroundColor: colors.primarySoft,
  },
});
