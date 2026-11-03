/**
 * @jest-environment node
 *
 * Calls every function in `api` over real HTTP against a Prism mock of
 * apps/api/openapi/v1.yaml started with `--errors`, so Prism rejects any request whose path,
 * query, headers or body break the contract. Run with `npm run test:contract`, which starts
 * Prism and points CONTRACT_API_URL at it.
 */
import { request as httpRequest } from 'node:http';

import { authToken } from '@/auth/token';

import { api } from '../endpoints';
import { ApiError } from '../errors';

jest.mock('@/config', () => ({
  config: {
    apiUrl: process.env.CONTRACT_API_URL ?? 'http://127.0.0.1:4011',
    paymentProvider: 'fake',
    webUrl: 'http://127.0.0.1:3000',
    privacyPolicyUrl: null,
    progressPollMs: 5_000,
  },
}));

const id = '0b8f0e2c-1c55-4a8b-9a0e-6f4f8d1a0001';
const addressInput = {
  recipient_name: 'Noura Alotaibi',
  recipient_phone: '+966512345678',
  city: 'Riyadh',
  district: 'Al Olaya',
  street: 'Olaya Main St',
  building_no: '2401',
  postal_code: '12211',
  additional_no: null,
  short_address: 'RRRD2929',
  is_default: true,
};

/**
 * The React Native test setup swaps `fetch` for a stub, so requests go out through node:http
 * here and come back as a standard Response.
 */
async function nodeFetch(input: Request): Promise<Response> {
  const body = input.method === 'GET' || input.method === 'HEAD' ? '' : await input.text();
  const headers: Record<string, string> = {};
  input.headers.forEach((value, key) => (headers[key] = value));
  return new Promise((resolve, reject) => {
    const req = httpRequest(input.url, { method: input.method, headers }, (res) => {
      const chunks: Buffer[] = [];
      res.on('data', (chunk: Buffer) => chunks.push(chunk));
      res.on('end', () => {
        const responseHeaders = new Headers();
        for (const [key, value] of Object.entries(res.headers)) {
          if (typeof value === 'string') responseHeaders.set(key, value);
        }
        const status = res.statusCode ?? 500;
        const text = Buffer.concat(chunks).toString('utf8');
        resolve(new Response(status === 204 ? null : text, { status, headers: responseHeaders }));
      });
    });
    req.on('error', reject);
    if (body) req.write(body);
    req.end();
  });
}

/** Fails with Prism's explanation when it rejected the request. */
async function expectAccepted<T>(promise: Promise<T>): Promise<T> {
  try {
    return await promise;
  } catch (error) {
    if (error instanceof ApiError) {
      throw new Error(`HTTP ${error.status}: ${JSON.stringify(error.body, null, 2)}`);
    }
    throw error;
  }
}

beforeAll(async () => {
  globalThis.fetch = nodeFetch as typeof fetch;
  // A request without a token must be refused for a protected route, proving Prism checks
  // security; the rest of the suite runs signed in.
  await expect(api.getMe()).rejects.toMatchObject({ status: 401 });
  authToken.set('contract-test-token');
});

const cases: [string, () => Promise<unknown>][] = [
  ['requestOtp', () => api.requestOtp('+966512345678')],
  [
    'verifyOtp',
    () => api.verifyOtp({ challenge_id: id, code: '123456', device_name: 'Contract test' }),
  ],
  ['getMe', () => api.getMe()],
  ['updateMe', () => api.updateMe({ name: 'Noura', email: null, locale: 'ar' })],
  ['acceptPrivacyPolicy', () => api.acceptPrivacyPolicy('2026-11')],
  ['listAddresses', () => api.listAddresses()],
  ['createAddress', () => api.createAddress(addressInput)],
  ['updateAddress', () => api.updateAddress(id, addressInput)],
  ['deleteAddress', () => api.deleteAddress(id)],
  ['registerDeviceToken', () => api.registerDeviceToken('ios', 'fcm-token-123')],
  ['listCategories', () => api.listCategories()],
  ['listCampaigns', () => api.listCampaigns({ filter: 'open', category: id, per_page: 20 })],
  ['getCampaign', () => api.getCampaign(id)],
  ['getCampaignProgress', () => api.getCampaignProgress(id)],
  [
    'joinCampaign',
    () =>
      api.joinCampaign(id, {
        client_request_id: id,
        product_variant_id: id,
        quantity: 2,
        shown_unit_price_halalas: 6900,
        delivery_address_id: id,
      }),
  ],
  ['listMyParticipations', () => api.listMyParticipations({ status: 'authorized' })],
  ['getMyParticipation', () => api.getMyParticipation(id)],
  ['cancelMyParticipation', () => api.cancelMyParticipation(id)],
  ['retryParticipationPayment', () => api.retryParticipationPayment(id)],
  ['syncPayment', () => api.syncPayment(id, `fake_authorized_${id}`)],
  ['listMyOrders', () => api.listMyOrders({ per_page: 20 })],
  ['getMyOrder', () => api.getMyOrder(id)],
  ['logout', () => api.logout()],
];

it('covers every api function', () => {
  expect(cases.map(([name]) => name).sort()).toEqual(Object.keys(api).sort());
});

it.each(cases)('%s matches the contract', async (_name, run) => {
  await expectAccepted(run());
});
