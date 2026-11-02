import { screen, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import type { AdminCreateInput } from '../../api/types';
import { adminUser, page, role } from '../fixtures';
import { bodies, renderApp } from '../render';
import { API, server } from '../server';

const superAdmin = adminUser({
  id: '00000000-0000-4000-8000-0000000000aa',
  name: 'Huda',
  roles: ['super_admin'],
  permissions: [
    'admins.manage',
    'campaigns.view',
    'orders.view',
    'payments.view',
    'refunds.request',
    'approvals.view',
  ],
});
const finance = adminUser({
  id: '00000000-0000-4000-8000-0000000000bb',
  name: 'Faisal',
  email: 'faisal@example.com',
  phone: '+966500000002',
  roles: ['finance'],
  permissions: ['approvals.decide', 'campaigns.view'],
});
const support = adminUser({
  id: '00000000-0000-4000-8000-0000000000cc',
  name: 'Nora',
  email: 'nora@example.com',
  phone: '+966500000003',
  roles: ['support'],
  permissions: ['campaigns.view', 'orders.view'],
});

function serveAdmins() {
  server.use(
    http.get(`${API}/admin/admins`, () => HttpResponse.json(page([superAdmin, finance, support]))),
    http.get(`${API}/admin/roles`, () =>
      HttpResponse.json({
        data: [
          role(
            'support',
            ['campaigns.view', 'orders.view', 'payments.view', 'refunds.request', 'approvals.view'],
            true,
          ),
          role('finance', ['approvals.decide', 'campaigns.view'], false),
        ],
      }),
    ),
  );
}

describe('admins', () => {
  it('only offers blocking for an admin who holds permissions you lack, and nothing risky for yourself', async () => {
    serveAdmins();
    renderApp('/admins', { admin: superAdmin });

    const table = within(await screen.findByRole('table'));
    const financeRow = (await table.findByText('Faisal')).closest('tr')!;
    expect(within(financeRow).getByRole('button', { name: 'Edit' })).toBeDisabled();
    expect(
      within(financeRow).queryByRole('button', { name: 'Set a new password' }),
    ).not.toBeInTheDocument();
    expect(within(financeRow).getByRole('button', { name: /Block/ })).toBeEnabled();

    const supportRow = table.getByText('Nora').closest('tr')!;
    expect(within(supportRow).getByRole('button', { name: 'Edit' })).toBeEnabled();
    expect(within(supportRow).getByRole('button', { name: 'Set a new password' })).toBeEnabled();

    const ownRow = table.getByText('Huda').closest('tr')!;
    expect(within(ownRow).getByText('You')).toBeInTheDocument();
    expect(within(ownRow).queryByRole('button', { name: /Block/ })).not.toBeInTheDocument();
  });

  it('greys out roles you cannot grant and sends the phone in E.164', async () => {
    serveAdmins();
    const created = bodies<AdminCreateInput>();
    server.use(
      http.post(`${API}/admin/admins`, async ({ request }) => {
        await created.record(request);
        return HttpResponse.json({ data: support }, { status: 201 });
      }),
    );
    const { user } = renderApp('/admins', { admin: superAdmin });

    await user.click(await screen.findByRole('button', { name: /Add admin/ }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByRole('checkbox', { name: 'Finance' })).toBeDisabled();

    await user.type(within(dialog).getByLabelText('Email'), 'new@example.com');
    await user.type(within(dialog).getByLabelText('Name'), 'Reem');
    await user.type(within(dialog).getByLabelText('Mobile number'), '055 123 4567');
    await user.click(within(dialog).getByRole('checkbox', { name: 'Support' }));
    await user.type(within(dialog).getByLabelText('Password'), 'a-long-enough-secret');
    await user.click(within(dialog).getByRole('button', { name: 'Save' }));

    await screen.findByText('Admin added. Share the password with them privately.');
    expect(created.seen).toEqual([
      {
        email: 'new@example.com',
        name: 'Reem',
        phone: '+966551234567',
        roles: ['support'],
        password: 'a-long-enough-secret',
      },
    ]);
  });

  it('shows a phone number taken by another admin on the field', async () => {
    serveAdmins();
    server.use(
      http.patch(`${API}/admin/admins/${support.id}`, () =>
        HttpResponse.json(
          {
            message: 'Invalid',
            code: 'validation_failed',
            errors: { phone: ['This number belongs to another admin.'] },
          },
          { status: 422 },
        ),
      ),
    );
    const { user } = renderApp('/admins', { admin: superAdmin });

    const row = (await screen.findByText('Nora')).closest('tr')!;
    await user.click(within(row).getByRole('button', { name: 'Edit' }));
    const dialog = await screen.findByRole('dialog');
    const phone = within(dialog).getByLabelText('Mobile number');
    await user.clear(phone);
    await user.type(phone, '0500000002');
    await user.click(within(dialog).getByRole('button', { name: 'Save' }));

    expect(
      await within(dialog).findByText('This number belongs to another admin.'),
    ).toBeInTheDocument();
  });
});
