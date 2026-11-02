import { authToken } from '@/auth/token';
import { mockApi } from '@/test/fetch';
import { campaignDetail, ids } from '@/test/fixtures';

import { api } from '../endpoints';
import { ApiError } from '../errors';

describe('api client', () => {
  afterEach(async () => {
    await authToken.clear();
  });

  it('sends the conventions on every call', async () => {
    const server = mockApi().on('GET', `/campaigns/${ids.kettle}`, {
      body: { data: campaignDetail('en') },
    });
    authToken.set('token-123');

    const campaign = await api.getCampaign(ids.kettle);

    expect(campaign.id).toBe(ids.kettle);
    const request = server.last('GET', `/campaigns/${ids.kettle}`)!;
    expect(request.headers.get('Authorization')).toBe('Bearer token-123');
    expect(request.headers.get('Accept')).toBe('application/json');
    expect(request.headers.get('Accept-Language')).toBe('en');
    expect(request.headers.get('X-Request-Id')).toMatch(/^[0-9a-f-]{36}$/);
  });

  it('omits Authorization when signed out', async () => {
    const server = mockApi().on('GET', '/categories', { body: { data: [] } });
    await api.listCategories();
    expect(server.last('GET', '/categories')!.headers.has('Authorization')).toBe(false);
  });

  it('turns an error body into an ApiError with code and field errors', async () => {
    mockApi().on('POST', `/campaigns/${ids.kettle}/participations`, {
      status: 409,
      body: { message: 'The price changed.', code: 'price_changed', errors: {} },
    });

    const error = await api
      .joinCampaign(ids.kettle, {
        client_request_id: '11111111-1111-4111-8111-111111111111',
        product_variant_id: ids.kettleBlack,
        quantity: 2,
        shown_unit_price_halalas: 6900,
        delivery_address_id: ids.address,
      })
      .catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({
      status: 409,
      code: 'price_changed',
      message: 'The price changed.',
    });
  });

  it('reads validation errors and Retry-After', async () => {
    mockApi().on('POST', '/auth/otp', {
      status: 429,
      headers: { 'Retry-After': '42' },
      body: { message: 'Slow down.', errors: { phone: ['Too many codes.'] } },
    });
    const error = (await api.requestOtp('+966512345678').catch((e: unknown) => e)) as ApiError;
    expect(error.status).toBe(429);
    expect(error.retryAfterSeconds).toBe(42);
    expect(error.fieldError('phone')).toBe('Too many codes.');
  });

  it('forgets a token the API refused', async () => {
    mockApi().on('GET', '/me', { status: 401, body: { message: 'Unauthenticated.' } });
    authToken.set('expired');
    const listener = jest.fn();
    const unsubscribe = authToken.onInvalidated(listener);

    await expect(api.getMe()).rejects.toMatchObject({ status: 401 });

    expect(authToken.get()).toBeNull();
    expect(listener).toHaveBeenCalledTimes(1);
    unsubscribe();
  });

  it('reports a network failure as status 0', async () => {
    globalThis.fetch = jest.fn(async () => {
      throw new TypeError('Network request failed');
    }) as unknown as typeof fetch;
    const error = (await api.listCategories().catch((e: unknown) => e)) as ApiError;
    expect(error.isNetworkError).toBe(true);
    expect(error.code).toBe('network_error');
    expect(error.message).toBe("We can't reach the server. Check your connection and try again.");
  });

  it('sends the fake provider id to payment sync', async () => {
    const server = mockApi().on('POST', `/payments/${ids.payment}/sync`, { body: { data: {} } });
    await api.syncPayment(ids.payment, `fake_authorized_${ids.payment}`);
    expect(server.last('POST', `/payments/${ids.payment}/sync`)!.body).toEqual({
      provider_payment_id: `fake_authorized_${ids.payment}`,
    });
  });
});
