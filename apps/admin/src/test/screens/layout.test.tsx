import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { adminUser } from '../fixtures';
import { renderApp } from '../render';

describe('layout', () => {
  it('lists only the sections the admin may see', async () => {
    renderApp('/account', {
      admin: adminUser({
        roles: ['operations'],
        permissions: ['orders.view', 'purchasing.view', 'fulfilment.edit'],
      }),
    });

    expect(await screen.findByRole('menuitem', { name: /Orders/ })).toBeInTheDocument();
    expect(screen.getByRole('menuitem', { name: /Purchase orders/ })).toBeInTheDocument();
    expect(screen.queryByRole('menuitem', { name: /Payments/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('menuitem', { name: /Admins/ })).not.toBeInTheDocument();
  });

  it('refuses a page the admin has no permission for', async () => {
    renderApp('/payments', { admin: adminUser({ permissions: ['orders.view'] }) });
    expect(await screen.findByText('Not allowed')).toBeInTheDocument();
  });

  it('lays the portal out right to left in Arabic', async () => {
    localStorage.setItem('groupbuy.admin.locale', 'ar');
    renderApp('/account', { admin: adminUser({ permissions: ['orders.view'] }) });

    expect(await screen.findByRole('menuitem', { name: /الطلبات/ })).toBeInTheDocument();
    expect(document.documentElement).toHaveAttribute('dir', 'rtl');
    expect(document.documentElement).toHaveAttribute('lang', 'ar');
  });
});
