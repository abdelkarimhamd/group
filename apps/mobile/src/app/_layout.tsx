import '@/i18n';

import {
  IBMPlexSansArabic_400Regular,
  IBMPlexSansArabic_500Medium,
  IBMPlexSansArabic_600SemiBold,
  IBMPlexSansArabic_700Bold,
  useFonts,
} from '@expo-google-fonts/ibm-plex-sans-arabic';
import Ionicons from '@expo/vector-icons/Ionicons';
import { QueryClientProvider } from '@tanstack/react-query';
import { reloadAppAsync } from 'expo';
import { DefaultTheme, LocaleProvider, Stack, ThemeProvider } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect, useState } from 'react';

import { createQueryClient } from '@/api/query-client';
import { AuthProvider } from '@/auth/session';
import { applyLayoutDirection } from '@/i18n/locale';
import { useFormat } from '@/i18n/useFormat';
import { colors, fonts } from '@/theme/tokens';

void SplashScreen.preventAutoHideAsync();

const navigationTheme = {
  ...DefaultTheme,
  colors: {
    ...DefaultTheme.colors,
    primary: colors.primary,
    background: colors.background,
    card: colors.surface,
    text: colors.text,
    border: colors.border,
  },
};

export default function RootLayout() {
  const { t, locale, rtl } = useFormat();
  const [queryClient] = useState(createQueryClient);
  const [fontsLoaded, fontError] = useFonts({
    IBMPlexSansArabic_400Regular,
    IBMPlexSansArabic_500Medium,
    IBMPlexSansArabic_600SemiBold,
    IBMPlexSansArabic_700Bold,
    ...Ionicons.font,
  });
  // Native layout direction follows the language only after a reload.
  const [needsReload] = useState(() => applyLayoutDirection(locale));
  const ready = (fontsLoaded || fontError !== null) && !needsReload;

  useEffect(() => {
    if (needsReload) void reloadAppAsync('Apply layout direction');
  }, [needsReload]);

  useEffect(() => {
    if (ready) void SplashScreen.hideAsync();
  }, [ready]);

  if (!ready) return null;

  return (
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <LocaleProvider direction={rtl ? 'rtl' : 'ltr'}>
          <ThemeProvider value={navigationTheme}>
            <StatusBar style="dark" />
            <Stack
              screenOptions={{
                headerTitleStyle: { fontFamily: fonts.semibold },
                headerBackButtonDisplayMode: 'minimal',
                headerShadowVisible: false,
                headerStyle: { backgroundColor: colors.background },
                contentStyle: { backgroundColor: colors.background },
              }}>
              <Stack.Screen name="(tabs)" options={{ headerShown: false }} />
              <Stack.Screen name="campaign/[id]" options={{ title: '' }} />
              <Stack.Screen name="checkout/[campaignId]" options={{ title: t('checkout.title') }} />
              <Stack.Screen
                name="pay/[participationId]"
                options={{ title: t('pay.title'), gestureEnabled: false }}
              />
              <Stack.Screen name="joins/[id]" options={{ title: t('joins.detailTitle') }} />
              <Stack.Screen name="orders/[id]" options={{ title: '' }} />
              <Stack.Screen
                name="sign-in"
                options={{ title: t('common.signIn'), presentation: 'modal' }}
              />
              <Stack.Screen name="privacy" options={{ title: t('privacy.title') }} />
              <Stack.Screen name="profile" options={{ title: t('profile.title') }} />
              <Stack.Screen name="addresses/index" options={{ title: t('addresses.title') }} />
              <Stack.Screen name="addresses/edit" options={{ title: t('addresses.newTitle') }} />
            </Stack>
          </ThemeProvider>
        </LocaleProvider>
      </AuthProvider>
    </QueryClientProvider>
  );
}
