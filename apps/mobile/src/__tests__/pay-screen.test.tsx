/**
 * The payment screen with a provider form that can end in every way Moyasar's can: the
 * return (completed), or closed with or without a payment already made.
 */
import { join } from 'node:path';

import { configure, fireEvent, renderRouter, screen, waitFor } from 'expo-router/testing-library';

import { authToken } from '@/auth/token';
import { PENDING_CHECK_MS } from '@/lib/payment';
import { mockApi } from '@/test/fetch';
import type { Participation } from '@/api/types';
import { authorizedParticipation, ids, participation, user } from '@/test/fixtures';

const APP = join(__dirname, '..', 'app');
configure({ asyncUtilTimeout: 5_000 });

jest.mock('@/payments', () => {
  const { Pressable, Text } = jest.requireActual<typeof import('react-native')>('react-native');
  const { createElement: h } = jest.requireActual<typeof import('react')>('react');
  const button = (testID: string, onPress: () => void) =>
    h(Pressable, { testID, onPress }, h(Text, null, testID));
  return {
    PaymentSheet: ({
      visible,
      onResult,
    }: {
      visible: boolean;
      onResult: (result: unknown) => void;
    }) =>
      visible
        ? h(
            Pressable,
            null,
            button('sheet-return', () =>
              onResult({ kind: 'completed', providerPaymentId: 'pay_123' }),
            ),
            button('sheet-close-after-payment', () =>
              onResult({ kind: 'dismissed', providerPaymentId: 'pay_123' }),
            ),
            button('sheet-close', () => onResult({ kind: 'dismissed' })),
          )
        : null,
  };
});

const SYNC = `/payments/${ids.payment}/sync`;
const syncs = (server: ReturnType<typeof mockApi>) =>
  server.sent.filter((r) => r.method === 'POST' && r.path === SYNC);

/**
 * The API as the app sees it: the join reads back as the last sync left it. `results` are
 * what each sync finds at Moyasar, in order; the last one repeats.
 */
function api(...results: Participation[]) {
  authToken.set('test-token');
  let current = participation('en');
  return mockApi()
    .on('GET', '/me', { body: { data: user({ locale: 'en' }) } })
    .on('GET', `/me/participations/${ids.participation}`, () => ({ body: { data: current } }))
    .on('POST', SYNC, () => {
      current = results.length > 1 ? results.shift()! : (results[0] ?? current);
      return { body: { data: current } };
    });
}

afterEach(async () => {
  await authToken.clear();
});

describe('the payment screen', () => {
  it('records a payment the customer walked away from, and keeps the form open to them', async () => {
    const server = api(participation('en'));
    await renderRouter(APP, { initialUrl: `/pay/${ids.participation}` });

    await fireEvent.press(await screen.findByTestId('pay-button'));
    await fireEvent.press(await screen.findByTestId('sheet-close-after-payment'));

    await waitFor(() => expect(syncs(server)).toHaveLength(1));
    expect(syncs(server)[0].body).toEqual({ provider_payment_id: 'pay_123' });
    // Still waiting for a payment: the customer can open the form again.
    expect(await screen.findByTestId('pay-button')).toBeOnTheScreen();
    expect(screen.queryByText('Still processing')).toBeNull();
  });

  it('shows a hold found that way', async () => {
    api(authorizedParticipation('en'));
    await renderRouter(APP, { initialUrl: `/pay/${ids.participation}` });

    await fireEvent.press(await screen.findByTestId('pay-button'));
    await fireEvent.press(await screen.findByTestId('sheet-close-after-payment'));

    expect(await screen.findByText("You're in!")).toBeOnTheScreen();
  });

  it('does nothing when the form closes before a payment exists', async () => {
    const server = api();
    await renderRouter(APP, { initialUrl: `/pay/${ids.participation}` });

    await fireEvent.press(await screen.findByTestId('pay-button'));
    await fireEvent.press(await screen.findByTestId('sheet-close'));

    expect(await screen.findByTestId('pay-button')).toBeOnTheScreen();
    expect(syncs(server)).toHaveLength(0);
  });

  it('looks again by itself while the bank is processing', async () => {
    const server = api(participation('en'), participation('en'), authorizedParticipation('en'));
    await renderRouter(APP, { initialUrl: `/pay/${ids.participation}` });

    await fireEvent.press(await screen.findByTestId('pay-button'));
    await fireEvent.press(await screen.findByTestId('sheet-return'));

    expect(await screen.findByText('Still processing')).toBeOnTheScreen();
    expect(syncs(server)).toHaveLength(1);

    // No tap needed: the screen asks again after PENDING_CHECK_MS, and again.
    expect(
      await screen.findByText("You're in!", {}, { timeout: PENDING_CHECK_MS * 2 + 3_000 }),
    ).toBeOnTheScreen();
    expect(syncs(server)).toHaveLength(3);
    expect(
      syncs(server).every(
        (r) => (r.body as { provider_payment_id: string }).provider_payment_id === 'pay_123',
      ),
    ).toBe(true);
  }, 20_000);
});
