import { screen, waitFor, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import type {
  AdminPayment,
  LedgerAccountBalance,
  LedgerTransaction,
  MoyasarSettlement,
  MoyasarSettlementLine,
} from '../../api/types';
import { adminUser, approvalRequest, page, sar } from '../fixtures';
import { bodies, renderApp } from '../render';
import { API, server } from '../server';

const finance = adminUser({
  roles: ['finance'],
  permissions: [
    'payments.view',
    'payments.void',
    'ledger.view',
    'ledger.adjust',
    'approvals.view',
    'approvals.decide',
  ],
});
const support = adminUser({
  roles: ['support'],
  permissions: ['payments.view', 'refunds.request'],
});

const accounts: LedgerAccountBalance[] = [
  {
    code: 'bank',
    name: 'Bank',
    type: 'asset',
    debits: sar(100000),
    credits: sar(0),
    balance: sar(100000),
  },
  {
    code: 'payment_fees',
    name: 'Payment processing fees',
    type: 'expense',
    debits: sar(2500),
    credits: sar(0),
    balance: sar(2500),
  },
];

const capture: LedgerTransaction = {
  id: '77777777-7777-4777-8777-000000000001',
  type: 'capture',
  reference: 'capture:abc',
  description: 'Capture of order GB-1001',
  order: { id: '11111111-1111-4111-8111-000000000001', number: 'GB-1001' },
  approval_request_id: null,
  reverses_transaction_id: null,
  entries: [
    {
      account: 'moyasar_clearing',
      account_name: 'Moyasar clearing',
      direction: 'D',
      amount: sar(31000),
    },
    {
      account: 'customer_prepayments',
      account_name: 'Customer prepayments',
      direction: 'C',
      amount: sar(31000),
    },
  ],
  actor: { type: 'system', id: null, name: null },
  occurred_at: '2026-11-02T09:00:00Z',
};

function payment(overrides: Partial<AdminPayment> = {}): AdminPayment {
  return {
    id: '88888888-8888-4888-8888-000000000001',
    participation_id: '99999999-9999-4999-8999-000000000001',
    campaign: { id: '22222222-2222-4222-8222-000000000001', code: 'GB-2026-001' },
    provider_payment_id: 'pay_123',
    source_type: 'creditcard',
    scheme: 'mada',
    card_last4: '4242',
    status: 'failed',
    rejection_reason: null,
    authorized_amount: sar(12000),
    captured_amount: sar(0),
    refunded_amount: sar(0),
    expires_at: '2026-11-16T09:00:00Z',
    capture_cutoff_at: '2026-11-15T09:00:00Z',
    authorized_at: '2026-11-02T09:00:00Z',
    captured_at: null,
    voided_at: null,
    provider_status: 'authorized',
    last_synced_at: null,
    void_requestable: true,
    transactions: [],
    ...overrides,
  };
}

function serveLedger() {
  server.use(
    http.get(`${API}/admin/ledger/accounts`, () => HttpResponse.json({ data: accounts })),
    http.get(`${API}/admin/ledger/transactions`, () => HttpResponse.json(page([capture]))),
    http.get(`${API}/admin/ledger/adjustments`, () => HttpResponse.json(page([]))),
  );
}

describe('the ledger', () => {
  it('shows each account with its balance', async () => {
    serveLedger();
    renderApp('/ledger', { admin: finance });

    const row = (await screen.findByText('Payment processing fees')).closest('tr')!;
    expect(within(row).getByText('Expense')).toBeInTheDocument();
    expect(within(row).getAllByText('25.00 SAR').length).toBe(2);
  });

  it('asks for balanced lines and refuses to send unbalanced ones', async () => {
    serveLedger();
    const sent = bodies();
    server.use(
      http.post(`${API}/admin/ledger/adjustments`, async ({ request }) => {
        await sent.record(request);
        return HttpResponse.json({ data: {} }, { status: 201 });
      }),
    );
    const { user } = renderApp('/ledger', { admin: finance });

    await user.click(await screen.findByRole('button', { name: /New adjustment/ }));
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText(/Description/), 'Bank charge for October');
    await user.click(within(dialog).getByRole('combobox', { name: 'Account 1' }));
    await user.click(await screen.findByTitle('Payment processing fees'));
    await user.click(within(dialog).getByRole('combobox', { name: 'Account 2' }));
    await user.click((await screen.findAllByTitle('Bank')).at(-1)!);
    await user.type(within(dialog).getByLabelText('Amount 1'), '15');
    await user.type(within(dialog).getByLabelText('Amount 2'), '14');

    const submit = within(dialog).getByRole('button', { name: 'Send for approval' });
    expect(within(dialog).getByText(/Debits and credits must be equal/)).toBeInTheDocument();
    expect(submit).toBeDisabled();

    await user.clear(within(dialog).getByLabelText('Amount 2'));
    await user.type(within(dialog).getByLabelText('Amount 2'), '15');
    await user.click(submit);

    await waitFor(() =>
      expect(sent.seen).toEqual([
        {
          description: 'Bank charge for October',
          lines: [
            { account: 'payment_fees', direction: 'D', amount_halalas: 1500 },
            { account: 'bank', direction: 'C', amount_halalas: 1500 },
          ],
        },
      ]),
    );
  });

  it('asks for a posting to be reversed', async () => {
    serveLedger();
    const sent = bodies();
    server.use(
      http.post(`${API}/admin/ledger/adjustments`, async ({ request }) => {
        await sent.record(request);
        return HttpResponse.json({ data: {} }, { status: 201 });
      }),
    );
    const { user } = renderApp('/ledger?tab=postings', { admin: finance });

    const row = (await screen.findByText('Capture of order GB-1001')).closest('tr')!;
    await user.click(within(row).getByRole('button', { name: /Reverse/ }));
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText(/Description/), 'Posted twice');
    await user.click(within(dialog).getByRole('button', { name: 'Send for approval' }));

    await waitFor(() =>
      expect(sent.seen).toEqual([
        { description: 'Posted twice', reverses_transaction_id: capture.id },
      ]),
    );
  });

  it('offers no adjustments without ledger.adjust', async () => {
    serveLedger();
    renderApp('/ledger?tab=postings', {
      admin: { ...finance, permissions: ['ledger.view'] },
    });

    await screen.findByText('Capture of order GB-1001');
    expect(screen.queryByRole('button', { name: /New adjustment/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Reverse/ })).not.toBeInTheDocument();
  });
});

describe('Moyasar settlements', () => {
  it('lists payouts and what each one paid for', async () => {
    const settlement: MoyasarSettlement = {
      id: '44444444-4444-4444-8444-000000000001',
      provider_settlement_id: 'stl_1',
      gross: sar(40000),
      fee: sar(1100),
      fee_vat: sar(143),
      net: sar(38900),
      settled_at: '2026-11-04T06:00:00Z',
      line_count: 2,
      unmatched_count: 1,
      ledger_transaction_id: null,
      imported_at: '2026-11-04T07:00:00Z',
    };
    const lines: MoyasarSettlementLine[] = [
      {
        type: 'payment',
        provider_payment_id: 'pay_ours',
        payment_id: '88888888-8888-4888-8888-000000000001',
        amount: sar(29000),
        fee: sar(1000),
        fee_vat: sar(130),
        payment_amount: sar(30000),
        transacted_at: '2026-11-02T09:00:00Z',
      },
      {
        type: 'payment',
        provider_payment_id: 'pay_stranger',
        payment_id: null,
        amount: sar(9900),
        fee: sar(100),
        fee_vat: sar(13),
        payment_amount: sar(10000),
        transacted_at: '2026-11-02T10:00:00Z',
      },
    ];
    server.use(
      http.get(`${API}/admin/settlements`, () => HttpResponse.json(page([settlement]))),
      http.get(`${API}/admin/settlements/${settlement.id}/lines`, () =>
        HttpResponse.json(page(lines)),
      ),
    );
    const { user } = renderApp('/settlements', { admin: finance });

    const row = (await screen.findByText('stl_1')).closest('tr')!;
    expect(within(row).getByText('389.00 SAR')).toBeInTheDocument();
    await user.click(within(row).getByRole('button', { name: /expand/i }));

    expect(await screen.findByRole('link', { name: 'pay_ours' })).toHaveAttribute(
      'href',
      '/payments/88888888-8888-4888-8888-000000000001',
    );
    expect(screen.getByText(/Not ours/)).toBeInTheDocument();
  });
});

describe('releasing a stuck hold', () => {
  it('sends the reason for approval', async () => {
    const p = payment();
    const sent = bodies();
    server.use(
      http.get(`${API}/admin/payments/${p.id}`, () => HttpResponse.json({ data: p })),
      http.post(`${API}/admin/payments/${p.id}/void`, async ({ request }) => {
        await sent.record(request);
        return HttpResponse.json(
          { data: approvalRequest({ type: 'manual_void', subject_type: 'payment_authorization' }) },
          { status: 201 },
        );
      }),
    );
    const { user } = renderApp(`/payments/${p.id}`, { admin: finance });

    await user.click(await screen.findByRole('button', { name: /Release hold/ }));
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText(/Reason/), 'Orphan hold from reconciliation');
    await user.click(within(dialog).getByRole('button', { name: 'Send for approval' }));

    await waitFor(() => expect(sent.seen).toEqual([{ reason: 'Orphan hold from reconciliation' }]));
  });

  it('is offered only for a hold that no longer counts, to staff who may ask', async () => {
    const p = payment({ void_requestable: false, status: 'authorized' });
    server.use(http.get(`${API}/admin/payments/${p.id}`, () => HttpResponse.json({ data: p })));
    renderApp(`/payments/${p.id}`, { admin: finance });
    await screen.findByText('pay_123');
    expect(screen.queryByRole('button', { name: /Release hold/ })).not.toBeInTheDocument();
  });

  it('is hidden from staff without payments.void', async () => {
    const p = payment();
    server.use(http.get(`${API}/admin/payments/${p.id}`, () => HttpResponse.json({ data: p })));
    renderApp(`/payments/${p.id}`, { admin: support });
    await screen.findByText('pay_123');
    expect(screen.queryByRole('button', { name: /Release hold/ })).not.toBeInTheDocument();
  });
});
