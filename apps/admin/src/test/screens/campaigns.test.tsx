import { screen, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import type { AdminCampaign } from '../../api/types';
import type { Permission } from '../../auth/permissions';
import { adminUser, campaign, marginReport } from '../fixtures';
import { bodies, renderApp } from '../render';
import { API, server } from '../server';

function serveCampaign(c: AdminCampaign) {
  server.use(http.get(`${API}/admin/campaigns/${c.id}`, () => HttpResponse.json({ data: c })));
}

const viewer = (permissions: Permission[]) =>
  adminUser({ permissions: ['campaigns.view', ...permissions] });

describe('campaign actions', () => {
  it('shows only the actions the API lists for this admin', async () => {
    const c = campaign({ status: 'pending_approval', available_actions: ['approve', 'reject'] });
    serveCampaign(c);
    renderApp(`/campaigns/${c.id}`, { admin: viewer(['campaigns.approve']) });

    expect(await screen.findByRole('button', { name: /Approve/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Send back/ })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Cancel campaign/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Extend/ })).not.toBeInTheDocument();
  });

  it('blocks approval below the margin floor without the override permission', async () => {
    const c = campaign({ status: 'pending_approval', available_actions: ['approve'] });
    serveCampaign(c);
    server.use(
      http.get(`${API}/admin/campaigns/${c.id}/margin`, () =>
        HttpResponse.json({ data: marginReport(false) }),
      ),
    );
    const { user } = renderApp(`/campaigns/${c.id}`, { admin: viewer(['campaigns.approve']) });

    await user.click(await screen.findByRole('button', { name: /Approve/ }));
    const dialog = await screen.findByRole('dialog');
    expect(
      await within(dialog).findByText(/needs the margin override permission/),
    ).toBeInTheDocument();
    expect(
      within(dialog).getByRole('button', { name: /Approve below the margin floor/ }),
    ).toBeDisabled();
  });

  it('sends the override reason when the admin may override the margin', async () => {
    const c = campaign({ status: 'pending_approval', available_actions: ['approve'] });
    const approve = bodies<{ override_reason?: string }>();
    serveCampaign(c);
    server.use(
      http.get(`${API}/admin/campaigns/${c.id}/margin`, () =>
        HttpResponse.json({ data: marginReport(false) }),
      ),
      http.get(`${API}/admin/campaigns/${c.id}/events`, () =>
        HttpResponse.json({ data: [], meta: { next_cursor: null, per_page: 25 } }),
      ),
      http.post(`${API}/admin/campaigns/${c.id}/approve`, async ({ request }) => {
        await approve.record(request);
        return HttpResponse.json({ data: { ...c, status: 'scheduled', available_actions: [] } });
      }),
    );
    const { user } = renderApp(`/campaigns/${c.id}`, {
      admin: viewer(['campaigns.approve', 'campaigns.override_margin']),
    });

    await user.click(await screen.findByRole('button', { name: /Approve/ }));
    const dialog = await screen.findByRole('dialog');
    const confirm = await within(dialog).findByRole('button', {
      name: /Approve below the margin floor/,
    });
    expect(confirm).toBeDisabled();
    await user.type(within(dialog).getByRole('textbox'), 'Launch promotion agreed with supplier');
    await user.click(confirm);

    await screen.findByText('Campaign approved');
    expect(approve.seen).toEqual([{ override_reason: 'Launch promotion agreed with supplier' }]);
  });
});
