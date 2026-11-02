import { authorizedParticipation, participation, payment } from '@/test/fixtures';

import { payView } from '../payment';

const now = Date.now();
const base = { now, syncing: false, returnedFromProvider: false };

describe('payView', () => {
  it('opens the form for a fresh hold', () => {
    expect(payView(participation('en'), base)).toBe('ready');
  });

  it('shows confirming while the app syncs with the provider', () => {
    expect(payView(participation('en'), { ...base, syncing: true })).toBe('confirming');
  });

  it('waits for the bank after returning from the provider', () => {
    expect(payView(participation('en'), { ...base, returnedFromProvider: true })).toBe('pending');
  });

  it('offers another card after a decline', () => {
    const declined = participation('en', {
      payment: payment({ status: 'failed', rejection_reason: 'declined' }),
    });
    expect(payView(declined, base)).toBe('declined');
    expect(payView(participation('en', { payment: null }), base)).toBe('declined');
  });

  it('follows the participation status first', () => {
    expect(payView(authorizedParticipation('en'), base)).toBe('success');
    expect(payView(participation('en', { status: 'payment_failed' }), base)).toBe('failed');
    expect(payView(participation('en', { status: 'reservation_expired' }), base)).toBe('expired');
    expect(payView(participation('en', { status: 'cancelled' }), base)).toBe('other');
  });

  it('expires on the device clock when the reservation ran out', () => {
    const late = participation('en', { reservation_expires_at: new Date(now - 1).toISOString() });
    expect(payView(late, base)).toBe('expired');
  });
});
