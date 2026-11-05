import { screen, waitFor, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import type { PurchaseOrder } from '../../api/types';
import { adminUser, purchaseOrder } from '../fixtures';
import { renderApp } from '../render';
import { API, server } from '../server';

const operations = adminUser({
  roles: ['operations'],
  permissions: ['purchasing.view', 'purchasing.edit', 'fulfilment.edit'],
});
const receipt = {
  id: '77777777-7777-4777-8777-000000000001',
  warehouse_name: 'Riyadh pilot warehouse',
  received_at: '2026-11-20T08:00:00Z',
  received_by: { type: 'admin' as const, id: operations.id, name: operations.name },
  notes: null,
  lines: [],
  photo_urls: ['https://files.example.sa/receipts/1.jpg?signature=x'],
};
const document = {
  id: '88888888-8888-4888-8888-000000000001',
  kind: 'alibaba_invoice' as const,
  file_name: 'Alibaba invoice 7781.pdf',
  size_bytes: 1_572_864,
  url: 'https://files.example.sa/po/1.pdf?signature=x',
  uploaded_by: { type: 'admin' as const, id: operations.id, name: operations.name },
  uploaded_at: '2026-11-12T10:00:00Z',
};

function serve(po: PurchaseOrder) {
  server.use(
    http.get(`${API}/admin/purchase-orders/${po.id}`, () => HttpResponse.json({ data: po })),
  );
}

describe('purchase order files', () => {
  it('lists the papers and the warehouse photos', async () => {
    const po = purchaseOrder({ documents: [document], receipts: [receipt] });
    serve(po);
    renderApp(`/purchase-orders/${po.id}`, { admin: operations });

    const link = await screen.findByRole('link', { name: /Alibaba invoice 7781\.pdf/ });
    expect(link).toHaveAttribute('href', document.url);
    const row = link.closest('tr')!;
    expect(within(row).getByText('Alibaba invoice')).toBeInTheDocument();
    expect(within(row).getByText('1.5 MB')).toBeInTheDocument();
    expect(screen.getByRole('img', { name: 'Photo 1' })).toHaveAttribute(
      'src',
      receipt.photo_urls[0],
    );
    expect(screen.getByRole('button', { name: /Add photo/ })).toBeInTheDocument();
  });

  it('uploads a paper with the chosen type', async () => {
    const po = purchaseOrder();
    serve(po);
    const sent: { kind: string | null; file: boolean }[] = [];
    server.use(
      http.post(`${API}/admin/purchase-orders/${po.id}/documents`, async ({ request }) => {
        const form = await request.formData();
        const file = form.get('file');
        sent.push({
          kind: form.get('kind') as string | null,
          file: file !== null && typeof file !== 'string',
        });
        return HttpResponse.json({ data: { ...po, documents: [document] } }, { status: 201 });
      }),
    );
    const { user, container } = renderApp(`/purchase-orders/${po.id}`, { admin: operations });

    await screen.findByText(/No documents yet/);
    const input = container.querySelector<HTMLInputElement>('input[type="file"][accept*="pdf"]')!;
    await user.upload(input, new File(['%PDF-1.7'], 'invoice.pdf', { type: 'application/pdf' }));

    await waitFor(() => expect(sent).toEqual([{ kind: 'alibaba_invoice', file: true }]));
  });

  it('shows no upload or remove controls without purchasing.edit', async () => {
    const po = purchaseOrder({ documents: [document] });
    serve(po);
    renderApp(`/purchase-orders/${po.id}`, {
      admin: adminUser({ roles: ['finance'], permissions: ['purchasing.view'] }),
    });

    await screen.findByRole('link', { name: /Alibaba invoice 7781\.pdf/ });
    expect(screen.queryByRole('button', { name: /Upload/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Remove' })).not.toBeInTheDocument();
  });
});
