import { act, fireEvent, render, screen } from '@testing-library/react-native';
import { Linking, Platform } from 'react-native';
import type { WebViewProps } from 'react-native-webview';
import {
  createOnShouldStartLoadWithRequest,
  defaultOriginWhitelist,
} from 'react-native-webview/lib/WebViewShared';

import '@/i18n';
import { ids, payment } from '@/test/fixtures';

import { MoyasarPaymentSheet } from '../MoyasarPaymentSheet';
import type { PaymentSheetResult } from '../types';

jest.mock('@/config', () => ({
  config: { webUrl: 'https://example.sa', paymentProvider: 'moyasar' },
}));

// The native WebView does not run under Jest: keep its props so tests can play the page.
let web: WebViewProps;
jest.mock('react-native-webview', () => {
  const { View } = jest.requireActual<typeof import('react-native')>('react-native');
  const { createElement } = jest.requireActual<typeof import('react')>('react');
  return {
    WebView: (props: WebViewProps) => {
      web = props;
      return createElement(View, { testID: props.testID });
    },
  };
});

const checkout = {
  ...payment().checkout!,
  callback_url: `https://example.sa/payments/return?payment_id=${ids.payment}`,
};
const RETURN = `${checkout.callback_url}&id=pay_123&status=authorized&message=APPROVED`;

async function open() {
  const onResult = jest.fn<void, [PaymentSheetResult]>();
  await render(
    <MoyasarPaymentSheet visible paymentId={ids.payment} checkout={checkout} onResult={onResult} />,
  );
  return onResult;
}

const message = (data: unknown) =>
  act(() => web.onMessage!({ nativeEvent: { data: JSON.stringify(data) } } as never));
const navigate = (url: string) =>
  web.onShouldStartLoadWithRequest!({ url, isTopFrame: true } as never);

describe('the Moyasar payment sheet', () => {
  it("opens the website's payment page with the checkout, Apple Pay on iPhone", async () => {
    await open();
    expect(web.source).toEqual({
      uri: `https://example.sa/en/payments/app#${encodeURIComponent(JSON.stringify(checkout))}`,
    });
    expect(web.enableApplePay).toBe(Platform.OS === 'ios');
    // Apple Pay in a WebView allows no injected script.
    expect(web.injectedJavaScript).toBeUndefined();
    expect(web.injectedJavaScriptBeforeContentLoaded).toBeUndefined();
  });

  it("ends on the form's return without loading it, with Moyasar's ID", async () => {
    const onResult = await open();
    expect(navigate('https://example.sa/en/payments/app#x')).toBe(true);
    expect(navigate('https://acs.bank.example/challenge')).toBe(true);
    expect(onResult).not.toHaveBeenCalled();

    expect(navigate(RETURN)).toBe(false);
    expect(onResult).toHaveBeenCalledTimes(1);
    expect(onResult).toHaveBeenCalledWith({ kind: 'completed', providerPaymentId: 'pay_123' });

    // Android may report the same return again as a navigation: still one result.
    await act(() => web.onNavigationStateChange!({ url: RETURN } as never));
    expect(onResult).toHaveBeenCalledTimes(1);
  });

  it('catches a return the platform only reports as a navigation', async () => {
    const onResult = await open();
    await act(() => web.onNavigationStateChange!({ url: RETURN } as never));
    expect(onResult).toHaveBeenCalledWith({ kind: 'completed', providerPaymentId: 'pay_123' });
  });

  it('uses the ID from the page when the return has none', async () => {
    const onResult = await open();
    await message({ type: 'moyasar_payment', id: 'pay_from_page' });
    expect(navigate(`${checkout.callback_url}&status=failed`)).toBe(false);
    expect(onResult).toHaveBeenCalledWith({
      kind: 'completed',
      providerPaymentId: 'pay_from_page',
    });
  });

  it('closes as dismissed before Moyasar has a payment', async () => {
    const onResult = await open();
    await message({ type: 'something_else', id: 'pay_x' });
    await fireEvent.press(screen.getByTestId('moyasar-close'));
    expect(onResult).toHaveBeenCalledWith({ kind: 'dismissed' });
  });

  it('closes during 3-D Secure with the payment Moyasar already made', async () => {
    const onResult = await open();
    await message({ type: 'moyasar_payment', id: 'pay_123' });
    await fireEvent.press(screen.getByTestId('moyasar-close'));
    expect(onResult).toHaveBeenCalledWith({ kind: 'dismissed', providerPaymentId: 'pay_123' });
  });

  it('loads web pages and their frames, and keeps every other link inside the form', async () => {
    const onResult = await open();
    // The navigation check as react-native-webview really runs it: its origin whitelist first,
    // then the sheet's own callback.
    const canOpenURL = jest.spyOn(Linking, 'canOpenURL').mockResolvedValue(true);
    const openURL = jest.spyOn(Linking, 'openURL').mockResolvedValue(undefined);
    const loadRequest = jest.fn();
    const check = createOnShouldStartLoadWithRequest(
      loadRequest,
      web.originWhitelist ?? defaultOriginWhitelist,
      web.onShouldStartLoadWithRequest,
    );
    const loads = (url: string) => {
      check({ nativeEvent: { url, lockIdentifier: 1 } } as never);
      return loadRequest.mock.lastCall?.[0] as boolean;
    };

    expect(loads('https://acs.bank.example/challenge')).toBe(true);
    expect(loads('about:blank')).toBe(true);
    expect(loads('about:srcdoc')).toBe(true);
    expect(loads('data:text/html,<form></form>')).toBe(true);
    expect(loads('blob:https://acs.bank.example/0f3a')).toBe(true);

    expect(loads('groupbuy://joins/1')).toBe(false);
    expect(loads('tel:+966500000000')).toBe(false);
    expect(loads('intent://pay#Intent;scheme=x;end')).toBe(false);
    expect(loads('javascript:alert(1)')).toBe(false);
    await act(async () => {});
    expect(canOpenURL).not.toHaveBeenCalled();
    expect(openURL).not.toHaveBeenCalled();
    expect(onResult).not.toHaveBeenCalled();

    expect(loads(RETURN)).toBe(false);
    expect(onResult).toHaveBeenCalledWith({ kind: 'completed', providerPaymentId: 'pay_123' });
    canOpenURL.mockRestore();
    openURL.mockRestore();
  });

  it('offers a reload when the page does not load', async () => {
    const onResult = await open();
    await act(() => web.onError!({ nativeEvent: {} } as never));
    expect(
      screen.getByText("The payment form didn't load. Check your connection and try again."),
    ).toBeOnTheScreen();
    expect(screen.queryByTestId('moyasar-webview')).toBeNull();
    await fireEvent.press(screen.getByTestId('moyasar-reload'));
    expect(screen.getByTestId('moyasar-webview')).toBeOnTheScreen();
    expect(onResult).not.toHaveBeenCalled();
  });

  it("ignores a bank page's HTTP error but not the payment page's", async () => {
    await open();
    const httpError = (url: string) =>
      act(() => web.onHttpError!({ nativeEvent: { url, statusCode: 500 } } as never));
    await httpError('https://acs.bank.example/challenge');
    expect(screen.getByTestId('moyasar-webview')).toBeOnTheScreen();
    await httpError('https://example.sa/en/payments/app');
    expect(screen.queryByTestId('moyasar-webview')).toBeNull();
  });
});
