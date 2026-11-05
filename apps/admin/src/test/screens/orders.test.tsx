import { screen, waitFor, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import type { AdminOrder, CourierSettings } from '../../api/types';
import { adminUser, order, orderReturn, page, sar, shipment } from '../fixtures';
import { bodies, renderApp } from '../render';
import { API, server } from '../server';

const operations = adminUser({
  roles: ['operations'],
  permissions: ['orders.view', 'fulfilment.edit'],
});
const support = adminUser({
  roles: ['support'],
  permissions: ['orders.view', 'refunds.request'],
});
const aramex: CourierSettings = { name: 'Aramex', books: true, tracks: true };
const byHand: CourierSettings = { name: null, books: false, tracks: false };

function serve(o: AdminOrder, courier: CourierSettings = aramex) {
  server.use(
    http.get(`${API}/admin/orders/${o.id}`, () => HttpResponse.json({ data: o })),
    http.get(`${API}/admin/courier`, () => HttpResponse.json({ data: courier })),
  );
}

describe('shipping an order', () => {
  it('books the parcel with the courier and sends the shipping cost', async () => {
    const o = order();
    serve(o);
    const sent = bodies();
    server.use(
      http.post(`${API}/admin/orders/${o.id}/shipment/booking`, async ({ request }) => {
        await sent.record(request);
        return HttpResponse.json(
          {
            data: shipment({
              tracking_number: null,
              booking: { ...shipment().booking!, status: 'requested', label_url: null },
            }),
          },
          { status: 202 },
        );
      }),
    );
    const { user } = renderApp(`/orders/${o.id}`, { admin: operations });

    await user.click(await screen.findByRole('button', { name: /Book with Aramex/ }));
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText(/Shipping cost/), '25');
    await user.click(within(dialog).getByRole('button', { name: 'Book' }));

    await waitFor(() => expect(sent.seen).toEqual([{ shipping_cost_halalas: 2500 }]));
    expect(screen.getByRole('button', { name: 'Ship by hand' })).toBeInTheDocument();
  });

  it('offers only shipping by hand without a courier API', async () => {
    const o = order();
    serve(o, byHand);
    renderApp(`/orders/${o.id}`, { admin: operations });

    expect(await screen.findByRole('button', { name: /Ship order/ })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Book with/ })).not.toBeInTheDocument();
  });

  it('shows the label of a booked parcel and lets staff call it off', async () => {
    const s = shipment();
    const o = order({ shipments: [s] });
    serve(o);
    const sent = bodies();
    server.use(
      http.post(`${API}/admin/shipments/${s.id}/cancel`, async ({ request }) => {
        await sent.record(request);
        return HttpResponse.json({ data: { ...s, status: 'cancelled' } });
      }),
    );
    const { user } = renderApp(`/orders/${o.id}`, { admin: operations });

    const label = await screen.findByRole('link', { name: /Download label/ });
    expect(label).toHaveAttribute('href', s.booking!.label_url);
    expect(screen.getByText(s.booking!.reference)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Book with/ })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: /Cancel shipment/ }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByText(/Cancel it in the courier's portal too/)).toBeInTheDocument();
    await user.type(within(dialog).getByLabelText(/Reason/), 'Customer moved');
    await user.click(within(dialog).getByRole('button', { name: 'Cancel shipment' }));

    await waitFor(() => expect(sent.seen).toEqual([{ reason: 'Customer moved' }]));
  });

  it('waits for the courier while a booking is out, with nothing to change', async () => {
    const s = shipment({
      tracking_number: null,
      tracking_url: null,
      booking: { ...shipment().booking!, status: 'requested', label_url: null },
    });
    const o = order({ shipments: [s] });
    serve(o);
    renderApp(`/orders/${o.id}`, { admin: operations });

    expect(
      await screen.findByText(/Waiting for Aramex to confirm the booking/),
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Cancel shipment/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Add tracking update/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Book with/ })).not.toBeInTheDocument();
  });

  it('explains a failed booking and offers shipping again', async () => {
    const s = shipment({
      status: 'cancelled',
      tracking_number: null,
      tracking_url: null,
      booking: {
        ...shipment().booking!,
        status: 'failed',
        error: 'ERR52 Consignee city is invalid',
        label_url: null,
      },
    });
    const o = order({ shipments: [s] });
    serve(o);
    renderApp(`/orders/${o.id}`, { admin: operations });

    expect(await screen.findByText('ERR52 Consignee city is invalid')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Book with Aramex/ })).toBeInTheDocument();
  });

  it('hides shipping actions from staff without fulfilment.edit', async () => {
    const o = order({ shipments: [shipment()] });
    serve(o);
    renderApp(`/orders/${o.id}`, { admin: support });

    await screen.findByRole('link', { name: /Download label/ });
    expect(screen.queryByRole('button', { name: /Cancel shipment/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Add tracking update/ })).not.toBeInTheDocument();
  });
});

describe('customer returns', () => {
  const delivered = () =>
    order({
      status: 'delivered',
      delivered_at: '2026-11-21T09:00:00Z',
      return_window_ends_at: '2099-01-01T00:00:00Z',
      shipments: [shipment({ status: 'delivered', shipped_at: '2026-11-20T10:00:00Z' })],
    });

  it('records a return within the window', async () => {
    const o = delivered();
    serve(o);
    const sent = bodies();
    server.use(
      http.post(`${API}/admin/orders/${o.id}/return`, async ({ request }) => {
        await sent.record(request);
        return HttpResponse.json({ data: orderReturn() }, { status: 201 });
      }),
    );
    const { user } = renderApp(`/orders/${o.id}`, { admin: support });

    await user.click(await screen.findByRole('button', { name: /Record return/ }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('combobox', { name: 'Reason' }));
    await user.click(await screen.findByTitle('Damaged in delivery'));
    await user.type(within(dialog).getByLabelText(/Note/), 'Box crushed');
    await user.click(within(dialog).getByRole('button', { name: 'Save' }));

    await waitFor(() =>
      expect(sent.seen).toEqual([{ reason: 'damaged_in_transit', note: 'Box crushed' }]),
    );
  });

  it('offers no return once the window has ended', async () => {
    const o = order({ ...delivered(), return_window_ends_at: '2025-11-28T09:00:00Z' });
    serve(o);
    renderApp(`/orders/${o.id}`, { admin: support });

    await screen.findByRole('button', { name: /Request refund/ });
    expect(screen.queryByRole('button', { name: /Record return/ })).not.toBeInTheDocument();
  });

  it('accepts a return for less than the full amount', async () => {
    const ret = orderReturn();
    const o = order({ ...delivered(), status: 'return_requested', return: ret });
    serve(o);
    const sent = bodies();
    server.use(
      http.post(`${API}/admin/returns/${ret.id}/approve`, async ({ request }) => {
        await sent.record(request);
        return HttpResponse.json({ data: { ...ret, status: 'approved', open: false } });
      }),
    );
    const { user } = renderApp(`/orders/${o.id}`, { admin: support });

    expect(await screen.findByText('Left earcup does not charge.')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Accept return/ }));
    const dialog = await screen.findByRole('dialog');
    const amount = within(dialog).getByLabelText('Amount');
    expect(amount).toHaveValue('310.00');
    await user.clear(amount);
    await user.type(amount, '300');
    await user.click(within(dialog).getByRole('button', { name: 'Send for approval' }));

    await waitFor(() => expect(sent.seen).toEqual([{ amount_halalas: 30000, note: null }]));
  });

  it('declines a return with a note', async () => {
    const ret = orderReturn();
    const o = order({ ...delivered(), status: 'return_requested', return: ret });
    serve(o);
    const sent = bodies();
    server.use(
      http.post(`${API}/admin/returns/${ret.id}/reject`, async ({ request }) => {
        await sent.record(request);
        return HttpResponse.json({ data: { ...ret, status: 'rejected', open: false } });
      }),
    );
    const { user } = renderApp(`/orders/${o.id}`, { admin: support });

    await user.click(await screen.findByRole('button', { name: /Decline return/ }));
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText('Reason'), 'Used for weeks');
    await user.click(within(dialog).getByRole('button', { name: 'Decline return' }));

    await waitFor(() => expect(sent.seen).toEqual([{ note: 'Used for weeks' }]));
  });

  it('shows a decided return without actions', async () => {
    const base = delivered();
    const ret = orderReturn({
      status: 'approved',
      open: false,
      decided_by: { type: 'admin', id: support.id, name: 'Huda' },
      decided_at: '2026-11-24T09:00:00Z',
      refund: {
        id: 'dddddddd-dddd-4ddd-8ddd-000000000001',
        payment_id: base.payment_id,
        customer_order_id: base.id,
        amount: sar(30000),
        method: 'refund',
        reason: 'customer_return',
        status: 'pending_approval',
        provider_refund_id: null,
        approval_request_id: null,
        credit_note_id: null,
        requested_by: { type: 'admin', id: support.id, name: 'Huda' },
        created_at: '2026-11-24T09:00:00Z',
        processed_at: null,
      },
    });
    const o = order({ ...base, status: 'return_requested', return: ret });
    serve(o);
    renderApp(`/orders/${o.id}`, { admin: support });

    expect(await screen.findByText('Accepted')).toBeInTheDocument();
    expect(screen.getAllByText('300.00 SAR').length).toBeGreaterThan(0);
    expect(screen.queryByRole('button', { name: /Accept return/ })).not.toBeInTheDocument();
  });

  it('lists returns with links to their orders', async () => {
    const ret = orderReturn();
    server.use(http.get(`${API}/admin/returns`, () => HttpResponse.json(page([ret]))));
    renderApp('/returns', { admin: support });

    const link = await screen.findByRole('link', { name: ret.order_number });
    expect(link).toHaveAttribute('href', `/orders/${ret.customer_order_id}`);
    const row = link.closest('tr')!;
    expect(within(row).getByText('Defective')).toBeInTheDocument();
    expect(within(row).getByText('Requested')).toBeInTheDocument();
  });
});
