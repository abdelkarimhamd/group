import { ApiError } from '@/api/errors';

import { deletionBlockers } from '../account';

const blocked = (body: unknown) =>
  new ApiError({ status: 409, message: 'Not yet', code: 'account_deletion_blocked', body });

describe('deletionBlockers', () => {
  it('lists what keeps the account, in a fixed order', () => {
    expect(
      deletionBlockers(
        blocked({ code: 'account_deletion_blocked', blocked_by: ['payments', 'joins'] }),
      ),
    ).toEqual(['joins', 'payments']);
  });

  it('drops reasons it does not know and still says the account is blocked', () => {
    expect(deletionBlockers(blocked({ blocked_by: ['loyalty_points'] }))).toEqual([]);
    expect(deletionBlockers(blocked(null))).toEqual([]);
  });

  it('is null for any other error, or none', () => {
    expect(deletionBlockers(new ApiError({ status: 500, message: 'Oops' }))).toBeNull();
    expect(deletionBlockers(new Error('boom'))).toBeNull();
    expect(deletionBlockers(null)).toBeNull();
  });
});
