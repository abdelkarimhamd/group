/**
 * The account screens through the real router: deleting the account, and the join and order
 * details that match the website.
 */
import { join } from 'node:path';

import { configure, fireEvent, renderRouter, screen, waitFor } from 'expo-router/testing-library';
import * as WebBrowser from 'expo-web-browser';
import { Alert } from 'react-native';

import { authToken } from '@/auth/token';
import { mockApi } from '@/test/fetch';
import {
  authorizedParticipation,
  ids,
  order,
  participation,
  payment,
  sar,
  user,
} from '@/test/fixtures';

jest.mock('expo-web-browser', () => ({ openBrowserAsync: jest.fn(async () => ({})) }));

const APP = join(__dirname, '..', 'app');

configure({ asyncUtilTimeout: 5_000 });

/** Answers every native alert by pressing its destructive (or last) button. */
function confirmAlerts() {
  return jest.spyOn(Alert, 'alert').mockImplementation((_title, _message, buttons) => {
    const button = buttons?.find((b) => b.style === 'destructive') ?? buttons?.at(-1);
    button?.onPress?.();
  });
}

beforeEach(() => {
  authToken.set('test-token');
});

afterEach(async () => {
  jest.restoreAllMocks();
  await authToken.clear();
});

describe('deleting the account', () => {
  it('says what is still open, then deletes and forgets the session', async () => {
    const alert = confirmAlerts();
    const server = mockApi()
      .on('GET', '/me', { body: { data: user({ locale: 'en' }) } })
      .on('DELETE', '/me', {
        status: 409,
        body: { message: 'Not yet', code: 'account_deletion_blocked', blocked_by: ['orders'] },
      })
      .on('DELETE', '/me', { status: 204 });

    await renderRouter(APP, { initialUrl: '/delete-account' });

    await fireEvent.press(await screen.findByTestId('confirm-delete-account'));
    expect(await screen.findByTestId('deletion-blocked')).toBeOnTheScreen();
    expect(
      screen.getByText('An order is still on its way or within its return window.'),
    ).toBeOnTheScreen();
    expect(authToken.get()).toBe('test-token');

    await fireEvent.press(screen.getByTestId('confirm-delete-account'));
    await waitFor(() => expect(authToken.get()).toBeNull());
    await waitFor(() =>
      expect(alert).toHaveBeenCalledWith(
        'Account deleted',
        'Your personal data was erased. Thank you for buying with us.',
      ),
    );
    // The token already stopped working: nothing is sent to sign out.
    expect(server.last('POST', '/auth/logout')).toBeUndefined();
    expect(server.sent.filter((r) => r.method === 'DELETE' && r.path === '/me')).toHaveLength(2);
  });

  it('is offered on the account screen', async () => {
    mockApi().on('GET', '/me', { body: { data: user({ locale: 'en', name: 'Noura' }) } });

    await renderRouter(APP, { initialUrl: '/account' });

    await fireEvent.press(await screen.findByTestId('delete-account'));
    expect(await screen.findByText('Delete my account')).toBeOnTheScreen();
  });
});

describe('join details', () => {
  it('shows the payment, the card and how long the hold lasts', async () => {
    mockApi().on('GET', `/me/participations/${ids.participation}`, {
      body: { data: authorizedParticipation('en') },
    });

    await renderRouter(APP, { initialUrl: `/joins/${ids.participation}` });

    expect(await screen.findByText('Payment status')).toBeOnTheScreen();
    expect(screen.getByText('Held')).toBeOnTheScreen();
    expect(screen.getByText('mada •••• 0000')).toBeOnTheScreen();
    expect(screen.getByText('Hold valid until')).toBeOnTheScreen();
    expect(
      screen.getByText('This is the most you will pay. The final charge may be lower.'),
    ).toBeOnTheScreen();
  });

  it('shows how much of the hold was released after the charge', async () => {
    mockApi().on('GET', `/me/participations/${ids.participation}`, {
      body: {
        data: participation('en', {
          status: 'captured',
          final_unit_price: sar(6000),
          capture_amount: sar(12000),
          order_id: ids.order,
          payment: payment({ status: 'captured', checkout: null, captured_amount: sar(12000) }),
        }),
      },
    });

    await renderRouter(APP, { initialUrl: `/joins/${ids.participation}` });

    expect(await screen.findByTestId('released-rest')).toHaveTextContent(
      /The rest of the hold, .*18\.00.* SAR.*, was released and never charged\./,
    );
  });
});

describe('order details', () => {
  const invoice = order('en').invoices[0];
  const withLink = (pdf_url: string | null) =>
    ({ body: { data: order('en', { invoices: [{ ...invoice, pdf_url }] }) } }) as const;

  it('says when the invoice is not ready yet', async () => {
    mockApi().on('GET', `/me/orders/${ids.order}`, withLink(null));

    await renderRouter(APP, { initialUrl: `/orders/${ids.order}` });

    expect(
      await screen.findByText('Being prepared. Pull down to refresh in a moment.'),
    ).toBeOnTheScreen();
    expect(screen.getByText(/^Placed /)).toBeOnTheScreen();
    expect(screen.getByTestId(`invoice-${invoice.id}`)).toBeDisabled();
  });

  it('opens a fresh invoice link, since each works for 30 minutes', async () => {
    const server = mockApi()
      .on('GET', `/me/orders/${ids.order}`, withLink('https://example.sa/old.pdf'))
      .on('GET', `/me/orders/${ids.order}`, withLink('https://example.sa/fresh.pdf'));

    await renderRouter(APP, { initialUrl: `/orders/${ids.order}` });

    await fireEvent.press(await screen.findByTestId(`invoice-${invoice.id}`));
    await waitFor(() =>
      expect(WebBrowser.openBrowserAsync).toHaveBeenCalledWith('https://example.sa/fresh.pdf'),
    );
    expect(server.sent.filter((r) => r.path === `/me/orders/${ids.order}`)).toHaveLength(2);
  });
});
