/**
 * The money path end to end through the real screens and router: open a deal, review,
 * reserve, approve the fake card form, land on the success screen.
 */
import { join } from 'node:path';

import { configure, fireEvent, renderRouter, screen, waitFor } from 'expo-router/testing-library';

import { authToken } from '@/auth/token';
import { mockApi } from '@/test/fetch';
import {
  address,
  authorizedParticipation,
  campaignDetail,
  ids,
  page,
  participation,
  progress,
  sar,
  user,
} from '@/test/fixtures';

const APP = join(__dirname, '..', 'app');

// Whole screens with the router mount slower than single components.
configure({ asyncUtilTimeout: 5_000 });

function signedInApi() {
  authToken.set('test-token');
  return mockApi()
    .on('GET', '/me', { body: { data: user({ locale: 'en', name: 'Noura' }) } })
    .on('GET', '/me/addresses', { body: { data: [address({ city: 'Riyadh' })] } })
    .on('GET', `/campaigns/${ids.kettle}`, { body: { data: campaignDetail('en') } })
    .on('GET', `/campaigns/${ids.kettle}/progress`, { body: { data: progress() } })
    .on('GET', '/me/participations', { body: page([]) });
}

afterEach(async () => {
  await authToken.clear();
});

describe('joining a deal', () => {
  it('reserves, holds the card through the fake provider and confirms', async () => {
    let held = false;
    const server = signedInApi()
      .on('POST', `/campaigns/${ids.kettle}/participations`, {
        status: 201,
        body: { data: participation('en') },
      })
      .on('GET', `/me/participations/${ids.participation}`, () => ({
        body: { data: held ? authorizedParticipation('en') : participation('en') },
      }))
      .on('POST', `/payments/${ids.payment}/sync`, () => {
        held = true;
        return { body: { data: authorizedParticipation('en') } };
      });

    await renderRouter(APP, { initialUrl: `/campaign/${ids.kettle}` });

    expect(await screen.findByText('Electric kettle 1.7 L')).toBeOnTheScreen();
    expect(screen.getByText('47 more needed')).toBeOnTheScreen();

    await fireEvent.press(screen.getByTestId('qty-increase'));
    await fireEvent.press(await screen.findByTestId('join-button'));

    expect(await screen.findByText('Reserve and pay')).toBeOnTheScreen();
    await fireEvent.press(screen.getByTestId('confirm-join'));

    await waitFor(() =>
      expect(server.last('POST', `/campaigns/${ids.kettle}/participations`)).toBeDefined(),
    );
    const joinBody = server.last('POST', `/campaigns/${ids.kettle}/participations`)!.body;
    expect(joinBody).toMatchObject({
      product_variant_id: ids.kettleBlack,
      quantity: 2,
      shown_unit_price_halalas: 6900,
      delivery_address_id: ids.address,
    });

    await fireEvent.press(await screen.findByTestId('pay-button'));
    await fireEvent.press(await screen.findByTestId('fake-pay-approve'));

    expect(await screen.findByText("You're in!")).toBeOnTheScreen();
    expect(server.last('POST', `/payments/${ids.payment}/sync`)!.body).toEqual({
      provider_payment_id: `fake_authorized_${ids.payment}`,
    });
  });

  it('shows the new price after a price change and starts a fresh attempt', async () => {
    const server = signedInApi().on('POST', `/campaigns/${ids.kettle}/participations`, {
      status: 409,
      body: {
        message: 'The price changed.',
        code: 'price_changed',
        current_unit_price: sar(6000),
      },
    });

    await renderRouter(APP, { initialUrl: `/checkout/${ids.kettle}?qty=2` });

    await fireEvent.press(await screen.findByTestId('confirm-join'));
    await waitFor(() => expect(screen.getAllByText(/120\.00⁩ SAR/).length).toBeGreaterThan(0));

    const first = server.last('POST', `/campaigns/${ids.kettle}/participations`)!.body as {
      client_request_id: string;
    };
    await fireEvent.press(screen.getByTestId('confirm-join'));
    await waitFor(() =>
      expect(
        server.sent.filter((r) => r.path === `/campaigns/${ids.kettle}/participations`),
      ).toHaveLength(2),
    );
    const second = server.last('POST', `/campaigns/${ids.kettle}/participations`)!.body as {
      client_request_id: string;
      shown_unit_price_halalas: number;
    };
    expect(second.client_request_id).not.toBe(first.client_request_id);
    expect(second.shown_unit_price_halalas).toBe(6000);
  });

  it('sends a signed-out customer to sign in and back to the review', async () => {
    const server = mockApi()
      .on('GET', `/campaigns/${ids.kettle}`, { body: { data: campaignDetail('en') } })
      .on('GET', `/campaigns/${ids.kettle}/progress`, { body: { data: progress() } })
      .on('POST', '/auth/otp', {
        status: 201,
        body: {
          data: {
            challenge_id: '3b9c2b0e-0000-4000-8000-000000000001',
            expires_at: new Date(Date.now() + 5 * 60_000).toISOString(),
            resend_available_at: new Date(Date.now() + 60_000).toISOString(),
          },
        },
      })
      .on('POST', '/auth/otp/verify', {
        body: {
          data: {
            token: 'new-token',
            token_type: 'Bearer',
            is_new_user: false,
            user: user({ locale: 'en' }),
          },
        },
      })
      .on('GET', '/me', { body: { data: user({ locale: 'en' }) } })
      .on('GET', '/me/addresses', { body: { data: [address()] } });

    const router = await renderRouter(APP, { initialUrl: `/campaign/${ids.kettle}` });
    await fireEvent.press(await screen.findByTestId('join-button'));

    await fireEvent.changeText(await screen.findByTestId('phone-input'), '05 1234 5678');
    await fireEvent.press(screen.getByTestId('send-code'));
    await waitFor(() =>
      expect(server.last('POST', '/auth/otp')?.body).toEqual({ phone: '+966512345678' }),
    );

    await fireEvent.changeText(await screen.findByTestId('code-input'), '١٢٣٤٥٦');
    await waitFor(() => expect(server.last('POST', '/auth/otp/verify')).toBeDefined());
    expect(server.last('POST', '/auth/otp/verify')!.body).toMatchObject({ code: '123456' });

    expect(await screen.findByText('Reserve and pay')).toBeOnTheScreen();
    expect(router.getPathname()).toBe(`/checkout/${ids.kettle}`);
    expect(authToken.get()).toBe('new-token');
  });
});

describe('the campaign screen', () => {
  it('takes a status change that comes with the live numbers', async () => {
    mockApi()
      .on('GET', `/campaigns/${ids.kettle}`, { body: { data: campaignDetail('en') } })
      .on('GET', `/campaigns/${ids.kettle}/progress`, {
        body: { data: progress({ status: 'cancelled', version: 11 }) },
      });

    await renderRouter(APP, { initialUrl: `/campaign/${ids.kettle}` });

    expect(
      await screen.findByText('This deal was cancelled. Every hold was released or refunded.'),
    ).toBeOnTheScreen();
    expect(screen.getByText('Cancelled')).toBeOnTheScreen();
    expect(screen.queryByTestId('join-button')).toBeNull();
  });
});
