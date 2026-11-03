import { useEffect, useRef, useState, type RefObject } from 'react';
import { ActivityIndicator, Modal, Platform, StyleSheet, View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { WebView } from 'react-native-webview';

import { Button } from '@/components/Button';
import { Notice } from '@/components/Notice';
import { Text } from '@/components/Text';
import { config } from '@/config';
import { useFormat } from '@/i18n/useFormat';
import { colors, space } from '@/theme/tokens';

import { paymentPageUrl, providerPaymentIdFromMessage, returnFromForm } from './moyasarPage';
import type { PaymentSheetProps, PaymentSheetResult } from './types';

/**
 * Moyasar's payment form (cards, and Apple Pay on iPhone) inside the app. It is the website's
 * own form, opened in a WebView, so card details go from Moyasar's form to Moyasar and the app
 * only learns Moyasar's payment ID: first from the page, as soon as the payment exists, then
 * from the form's return to the checkout's callback_url, which the WebView never loads.
 */
export function MoyasarPaymentSheet(props: PaymentSheetProps) {
  // The open form says what closing means (whether Moyasar already has a payment).
  const closeRef = useRef<(() => void) | null>(null);
  return (
    <Modal
      visible={props.visible}
      animationType="slide"
      presentationStyle="pageSheet"
      onRequestClose={() => closeRef.current?.()}>
      {/* Mounted per opening, so each attempt starts with a fresh form. */}
      {props.visible ? <MoyasarForm {...props} closeRef={closeRef} /> : null}
    </Modal>
  );
}

function MoyasarForm({
  checkout,
  onResult,
  closeRef,
}: PaymentSheetProps & { closeRef: RefObject<(() => void) | null> }) {
  const { t, locale } = useFormat();
  const [attempt, setAttempt] = useState(0);
  const [failed, setFailed] = useState(false);
  const providerPaymentId = useRef<string | null>(null);
  const done = useRef(false);

  const finish = (result: PaymentSheetResult) => {
    if (done.current) return;
    done.current = true;
    onResult(result);
  };
  const close = () =>
    finish(
      providerPaymentId.current
        ? { kind: 'dismissed', providerPaymentId: providerPaymentId.current }
        : { kind: 'dismissed' },
    );

  useEffect(() => {
    closeRef.current = close;
    return () => {
      closeRef.current = null;
    };
  });

  /** True when `url` is the form's return, which ends the sheet instead of loading. */
  const caughtReturn = (url: string) => {
    const back = returnFromForm(url, checkout);
    if (!back) return false;
    const id = back.providerPaymentId ?? providerPaymentId.current;
    finish(id ? { kind: 'completed', providerPaymentId: id } : { kind: 'dismissed' });
    return true;
  };

  return (
    <SafeAreaView style={styles.sheet} edges={['top', 'bottom']}>
      <View style={styles.header}>
        <Text variant="title">{t('pay.title')}</Text>
        <Button
          testID="moyasar-close"
          variant="ghost"
          compact
          title={t('common.close')}
          onPress={close}
        />
      </View>
      {failed ? (
        <View style={styles.failed}>
          <Notice tone="danger" message={t('pay.formFailed')} />
          <Button
            testID="moyasar-reload"
            title={t('common.retry')}
            onPress={() => {
              setFailed(false);
              setAttempt((n) => n + 1);
            }}
          />
        </View>
      ) : (
        <WebView
          key={attempt}
          testID="moyasar-webview"
          style={styles.web}
          source={{ uri: paymentPageUrl(config.webUrl, locale, checkout) }}
          // Apple Pay in a WebView needs this, and then no script may be injected: the page
          // talks to the app through WebKit's message handler.
          enableApplePay={Platform.OS === 'ios'}
          startInLoadingState
          renderLoading={() => (
            <View style={styles.loading}>
              <ActivityIndicator size="large" color={colors.primary} />
            </View>
          )}
          onMessage={(event) => {
            const id = providerPaymentIdFromMessage(event.nativeEvent.data);
            if (id) providerPaymentId.current = id;
          }}
          onShouldStartLoadWithRequest={(request) => {
            if (caughtReturn(request.url)) return false;
            // Only web pages: no app links, phone numbers or intents from inside the form.
            return /^(https?:|about:blank|about:srcdoc|data:)/i.test(request.url);
          }}
          // A return the platform did not offer to the callback above (Android does not for
          // every redirect) is caught as it starts loading.
          onNavigationStateChange={(navigation) => void caughtReturn(navigation.url)}
          onError={() => setFailed(true)}
          onHttpError={(event) => {
            // Only the payment page itself; 3-D Secure pages are the bank's business.
            if (event.nativeEvent.url.startsWith(`${config.webUrl}/${locale}/payments/app`)) {
              setFailed(true);
            }
          }}
          webviewDebuggingEnabled={__DEV__}
        />
      )}
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  sheet: {
    flex: 1,
    backgroundColor: colors.background,
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingHorizontal: space.lg,
    paddingVertical: space.md,
  },
  web: {
    flex: 1,
    backgroundColor: colors.background,
  },
  loading: {
    ...StyleSheet.absoluteFill,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: colors.background,
  },
  failed: {
    padding: space.lg,
    gap: space.md,
  },
});
