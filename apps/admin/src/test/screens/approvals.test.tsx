import { screen, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { adminUser, approvalRequest, page } from '../fixtures';
import { renderApp } from '../render';
import { API, server } from '../server';

const me = adminUser({ id: '00000000-0000-4000-8000-000000000001', roles: ['finance'] });
const mine = approvalRequest({
  id: '55555555-5555-4555-8555-000000000001',
  requested_by: { type: 'admin', id: me.id, name: me.name },
});
const theirs = approvalRequest({
  id: '55555555-5555-4555-8555-000000000002',
  type: 'campaign_cancellation',
});

describe('approvals', () => {
  it('never lets the admin who asked decide their own request', async () => {
    server.use(
      http.get(`${API}/admin/approval-requests`, () => HttpResponse.json(page([mine, theirs]))),
    );
    renderApp('/approvals', {
      admin: { ...me, permissions: ['approvals.view', 'approvals.decide'] },
    });

    const ownRow = (await screen.findAllByText('Refund'))[0]!.closest('tr')!;
    const otherRow = screen.getByText('Campaign cancellation').closest('tr')!;
    expect(within(ownRow).getByRole('button', { name: /Approve/ })).toBeDisabled();
    expect(within(ownRow).getByRole('button', { name: /Reject/ })).toBeDisabled();
    expect(within(otherRow).getByRole('button', { name: /Approve/ })).toBeEnabled();
  });

  it('hides decisions from an admin without approvals.decide', async () => {
    server.use(http.get(`${API}/admin/approval-requests`, () => HttpResponse.json(page([theirs]))));
    renderApp('/approvals', {
      admin: { ...me, roles: ['support'], permissions: ['approvals.view'] },
    });

    await screen.findByText('Campaign cancellation');
    expect(screen.queryByRole('button', { name: /Approve/ })).not.toBeInTheDocument();
  });
});
