import { screen, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { adminUser, page, supplier } from '../fixtures';
import { bodies, renderApp } from '../render';
import { API, server } from '../server';

const s = supplier({ status: 'prospect', quality_rating: 4 });

function serveSupplier() {
  server.use(
    http.get(`${API}/admin/suppliers/${s.id}`, () => HttpResponse.json({ data: s })),
    http.get(`${API}/admin/quotations`, () => HttpResponse.json(page([]))),
  );
}

describe('supplier review', () => {
  it('approves through the review endpoint, separate from editing', async () => {
    serveSupplier();
    const reviews = bodies();
    server.use(
      http.post(`${API}/admin/suppliers/${s.id}/review`, async ({ request }) => {
        await reviews.record(request);
        return HttpResponse.json({ data: { ...s, status: 'approved' } });
      }),
    );
    const { user } = renderApp(`/suppliers/${s.id}`, {
      admin: adminUser({
        roles: ['finance'],
        permissions: ['suppliers.view', 'suppliers.approve'],
      }),
    });

    expect(await screen.findByRole('button', { name: /Approve/ })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Edit/ })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Approve/ }));
    const dialog = await screen.findByRole('dialog');
    await user.type(within(dialog).getByLabelText('Review notes'), 'Samples checked');
    await user.click(within(dialog).getByRole('button', { name: 'Approve supplier' }));

    await screen.findByText('Saved');
    expect(reviews.seen).toEqual([
      { status: 'approved', quality_rating: 4, review_notes: 'Samples checked' },
    ]);
  });

  it('lets sourcing edit but not approve', async () => {
    serveSupplier();
    renderApp(`/suppliers/${s.id}`, {
      admin: adminUser({ roles: ['sourcing'], permissions: ['suppliers.view', 'suppliers.edit'] }),
    });

    expect(await screen.findByRole('button', { name: /Edit/ })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Approve/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Block/ })).not.toBeInTheDocument();
  });
});
