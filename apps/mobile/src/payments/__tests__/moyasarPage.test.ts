import { ids, payment } from '@/test/fixtures';

import { paymentPageUrl, providerPaymentIdFromMessage, returnFromForm } from '../moyasarPage';

const checkout = {
  ...payment().checkout!,
  callback_url: `https://example.sa/payments/return?payment_id=${ids.payment}`,
};

describe('the payment page URL', () => {
  it('carries the checkout in the fragment, in the app language', () => {
    const url = paymentPageUrl('https://example.sa', 'en', checkout);
    expect(url.startsWith('https://example.sa/en/payments/app#')).toBe(true);
    // The website reads it back with JSON.parse(decodeURIComponent(hash)).
    expect(JSON.parse(decodeURIComponent(url.split('#')[1]))).toEqual(checkout);
  });
});

describe("the page's message", () => {
  it("gives Moyasar's payment ID", () => {
    expect(providerPaymentIdFromMessage('{"type":"moyasar_payment","id":"pay_123"}')).toBe(
      'pay_123',
    );
  });

  it.each([
    'not json',
    'null',
    '"pay_123"',
    '{"type":"other","id":"pay_123"}',
    '{"type":"moyasar_payment"}',
    '{"type":"moyasar_payment","id":42}',
    '{"type":"moyasar_payment","id":"pay/../x"}',
    `{"type":"moyasar_payment","id":"${'x'.repeat(65)}"}`,
  ])('ignores %s', (data) => {
    expect(providerPaymentIdFromMessage(data)).toBeNull();
  });
});

describe("the form's return", () => {
  const back = (query: string) =>
    `https://example.sa/payments/return?payment_id=${ids.payment}&${query}`;

  it("is caught with Moyasar's ID", () => {
    expect(returnFromForm(back('id=pay_123&status=authorized&message=APPROVED'), checkout)).toEqual(
      { providerPaymentId: 'pay_123' },
    );
  });

  it('is caught even when the message is not encoded', () => {
    expect(
      returnFromForm(back('id=pay_123&status=failed&message=3-D Secure 100% failed'), checkout),
    ).toEqual({ providerPaymentId: 'pay_123' });
  });

  it('is caught after the website added a language, or without our payment_id', () => {
    expect(returnFromForm('https://EXAMPLE.sa/ar/payments/return?id=pay_123#x', checkout)).toEqual({
      providerPaymentId: 'pay_123',
    });
  });

  it('is caught without a usable ID, so the sheet still closes', () => {
    expect(returnFromForm(back('status=failed'), checkout)).toEqual({ providerPaymentId: null });
    expect(returnFromForm(back('id=../x'), checkout)).toEqual({ providerPaymentId: null });
  });

  it.each([
    'https://example.sa/en/payments/app#{}',
    'https://example.sa/payments/returned?id=pay_123',
    'https://example.sa/fr/payments/return?id=pay_123',
    'https://example.sa.evil.example/payments/return?id=pay_123',
    'http://example.sa/payments/return?id=pay_123',
    'https://acs.bank.example/3ds?return=https://example.sa/payments/return',
    'groupbuy://payments/return?id=pay_123',
  ])('lets %s load', (url) => {
    expect(returnFromForm(url, checkout)).toBeNull();
  });
});
