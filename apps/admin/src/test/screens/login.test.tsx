import { screen } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { adminUser } from '../fixtures';
import { bodies, renderApp } from '../render';
import { API, server } from '../server';

const challenge = {
  challenge_id: '77777777-7777-4777-8777-777777777777',
  expires_at: '2026-11-02T10:05:00Z',
  resend_available_at: '2026-11-02T10:01:00Z',
};

describe('sign-in', () => {
  it('signs in with password, then the SMS code, and lands on the dashboard', async () => {
    const login = bodies<{ email: string; password: string }>();
    const verify = bodies<{ challenge_id: string; code: string }>();
    const admin = adminUser({ name: 'Sara Alqahtani', permissions: [] });
    server.use(
      http.post(`${API}/admin/auth/login`, async ({ request }) => {
        await login.record(request);
        return HttpResponse.json({ data: challenge }, { status: 202 });
      }),
      http.post(`${API}/admin/auth/verify`, async ({ request }) => {
        await verify.record(request);
        return HttpResponse.json({ data: { token: 'tok', token_type: 'Bearer', admin } });
      }),
    );

    const { user } = renderApp('/');
    await user.type(await screen.findByLabelText('Email'), 'sara@example.com');
    await user.type(screen.getByLabelText('Password'), 'correct horse battery');
    await user.click(screen.getByRole('button', { name: 'Continue' }));

    // Arabic-Indic digits are accepted and sent as ASCII.
    await user.type(await screen.findByLabelText('Code'), '١٢٣٤٥٦');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(await screen.findByRole('heading', { name: /Sara Alqahtani/ })).toBeInTheDocument();
    expect(login.seen).toEqual([{ email: 'sara@example.com', password: 'correct horse battery' }]);
    expect(verify.seen).toEqual([{ challenge_id: challenge.challenge_id, code: '123456' }]);
    expect(sessionStorage.getItem('groupbuy.admin.token')).toBe('tok');
  });

  it('explains a wrong code and keeps the admin on the code step', async () => {
    server.use(
      http.post(`${API}/admin/auth/login`, () =>
        HttpResponse.json({ data: challenge }, { status: 202 }),
      ),
      http.post(`${API}/admin/auth/verify`, () =>
        HttpResponse.json(
          { message: 'Wrong code', code: 'otp_invalid', errors: {} },
          { status: 422 },
        ),
      ),
    );

    const { user } = renderApp('/login');
    await user.type(await screen.findByLabelText('Email'), 'sara@example.com');
    await user.type(screen.getByLabelText('Password'), 'x');
    await user.click(screen.getByRole('button', { name: 'Continue' }));
    await user.type(await screen.findByLabelText('Code'), '000000');
    await user.click(screen.getByRole('button', { name: 'Sign in' }));

    expect(await screen.findByText('That code is wrong.')).toBeInTheDocument();
    expect(screen.getByLabelText('Code')).toBeInTheDocument();
  });

  it('sends a signed-out visitor to the sign-in page', async () => {
    renderApp('/campaigns');
    expect(await screen.findByRole('button', { name: 'Continue' })).toBeInTheDocument();
  });
});
