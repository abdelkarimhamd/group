import { call, client } from './client';
import type {
  Address,
  AddressInput,
  CampaignDetail,
  CampaignFilter,
  CampaignProgress,
  CampaignSummary,
  Category,
  CustomerSession,
  JoinRequest,
  Order,
  OtpChallenge,
  Page,
  Participation,
  ParticipationStatus,
  Payment,
  User,
} from './types';

type PageParams = { cursor?: string; per_page?: number };

/**
 * One function per customer operation in apps/api/openapi/v1.yaml. Paths, parameters and
 * bodies are type-checked against the generated schema, so a contract change that breaks the
 * app fails `npm run typecheck`.
 */
export const api = {
  // Auth
  async requestOtp(phone: string): Promise<OtpChallenge> {
    const res = await call(client.POST('/auth/otp', { body: { phone } }));
    return res.data;
  },

  async verifyOtp(body: {
    challenge_id: string;
    code: string;
    device_name: string;
  }): Promise<CustomerSession> {
    const res = await call(client.POST('/auth/otp/verify', { body }));
    return res.data;
  },

  async logout(): Promise<void> {
    await call(client.POST('/auth/logout'));
  },

  // Account
  async getMe(): Promise<User> {
    const res = await call(client.GET('/me'));
    return res.data;
  },

  async updateMe(body: {
    name?: string | null;
    email?: string | null;
    locale?: 'ar' | 'en';
  }): Promise<User> {
    const res = await call(client.PATCH('/me', { body }));
    return res.data;
  },

  /**
   * Deletes the account (PDPL erasure). Every token of the account stops working, so the
   * caller forgets the session without signing out. A 409 `account_deletion_blocked` says in
   * `blocked_by` what is still open.
   */
  async deleteMe(): Promise<void> {
    await call(client.DELETE('/me'));
  },

  async acceptPrivacyPolicy(policyVersion: string): Promise<User> {
    const res = await call(
      client.POST('/me/privacy-consent', { body: { policy_version: policyVersion } }),
    );
    return res.data;
  },

  async listAddresses(): Promise<Address[]> {
    const res = await call(client.GET('/me/addresses'));
    return res.data;
  },

  async createAddress(body: AddressInput): Promise<Address> {
    const res = await call(client.POST('/me/addresses', { body }));
    return res.data;
  },

  async updateAddress(addressId: string, body: AddressInput): Promise<Address> {
    const res = await call(
      client.PATCH('/me/addresses/{address_id}', {
        params: { path: { address_id: addressId } },
        body,
      }),
    );
    return res.data;
  },

  async deleteAddress(addressId: string): Promise<void> {
    await call(
      client.DELETE('/me/addresses/{address_id}', {
        params: { path: { address_id: addressId } },
      }),
    );
  },

  async registerDeviceToken(platform: 'ios' | 'android' | 'web', token: string): Promise<void> {
    await call(client.POST('/me/device-tokens', { body: { platform, token } }));
  },

  // Catalog and campaigns
  async listCategories(): Promise<Category[]> {
    const res = await call(client.GET('/categories'));
    return res.data;
  },

  async listCampaigns(
    params: { filter?: CampaignFilter; category?: string } & PageParams = {},
  ): Promise<Page<CampaignSummary>> {
    return call(client.GET('/campaigns', { params: { query: params } }));
  },

  async getCampaign(campaignId: string): Promise<CampaignDetail> {
    const res = await call(
      client.GET('/campaigns/{campaign_id}', { params: { path: { campaign_id: campaignId } } }),
    );
    return res.data;
  },

  async getCampaignProgress(campaignId: string): Promise<CampaignProgress> {
    const res = await call(
      client.GET('/campaigns/{campaign_id}/progress', {
        params: { path: { campaign_id: campaignId } },
      }),
    );
    return res.data;
  },

  async joinCampaign(campaignId: string, body: JoinRequest): Promise<Participation> {
    const res = await call(
      client.POST('/campaigns/{campaign_id}/participations', {
        params: { path: { campaign_id: campaignId } },
        body,
      }),
    );
    return res.data;
  },

  // Participations and payments
  async listMyParticipations(
    params: { status?: ParticipationStatus } & PageParams = {},
  ): Promise<Page<Participation>> {
    return call(client.GET('/me/participations', { params: { query: params } }));
  },

  async getMyParticipation(participationId: string): Promise<Participation> {
    const res = await call(
      client.GET('/me/participations/{participation_id}', {
        params: { path: { participation_id: participationId } },
      }),
    );
    return res.data;
  },

  async cancelMyParticipation(participationId: string): Promise<Participation> {
    const res = await call(
      client.POST('/me/participations/{participation_id}/cancel', {
        params: { path: { participation_id: participationId } },
      }),
    );
    return res.data;
  },

  async retryParticipationPayment(participationId: string): Promise<Payment> {
    const res = await call(
      client.POST('/me/participations/{participation_id}/payments', {
        params: { path: { participation_id: participationId } },
      }),
    );
    return res.data;
  },

  async syncPayment(paymentId: string, providerPaymentId: string): Promise<Participation> {
    const res = await call(
      client.POST('/payments/{payment_id}/sync', {
        params: { path: { payment_id: paymentId } },
        body: { provider_payment_id: providerPaymentId },
      }),
    );
    return res.data;
  },

  // Orders
  async listMyOrders(params: PageParams = {}): Promise<Page<Order>> {
    return call(client.GET('/me/orders', { params: { query: params } }));
  },

  async getMyOrder(orderId: string): Promise<Order> {
    const res = await call(
      client.GET('/me/orders/{order_id}', { params: { path: { order_id: orderId } } }),
    );
    return res.data;
  },
};
