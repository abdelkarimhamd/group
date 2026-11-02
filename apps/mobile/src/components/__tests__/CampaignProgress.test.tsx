import { act, render, screen } from '@testing-library/react-native';

import i18n from '@/i18n';
import { campaignDetail, progress } from '@/test/fixtures';

import { CampaignProgress } from '../CampaignProgress';
import { TierLadder } from '../TierLadder';

const LRI = '⁦';
const PDI = '⁩';

async function inLanguage(locale: 'ar' | 'en') {
  await act(async () => {
    await i18n.changeLanguage(locale);
  });
}

afterEach(async () => {
  await inLanguage('en');
});

describe('CampaignProgress', () => {
  it('says how many more are needed before the target', async () => {
    await render(<CampaignProgress progress={progress()} />);
    expect(screen.getByText('47 more needed')).toBeOnTheScreen();
    expect(screen.getByText('53 of 100 claimed')).toBeOnTheScreen();
  });

  it('uses the singular for the last unit', async () => {
    await render(
      <CampaignProgress progress={progress({ units_to_target: 1, committed_qty: 99 })} />,
    );
    expect(screen.getByText('1 more needed')).toBeOnTheScreen();
  });

  it('switches to "deal confirmed" with stock left once the target is met', async () => {
    await render(
      <CampaignProgress
        progress={progress({
          committed_qty: 120,
          units_to_target: 0,
          remaining_qty: 30,
          deal_confirmed_at: '2026-11-02T09:00:00Z',
        })}
      />,
    );
    expect(screen.getByText('Deal confirmed')).toBeOnTheScreen();
    expect(screen.getByText('30 left')).toBeOnTheScreen();
    expect(screen.queryByText(/more needed/)).toBeNull();
  });

  it('shows sold out', async () => {
    await render(
      <CampaignProgress progress={progress({ units_to_target: 0, remaining_qty: 0 })} />,
    );
    expect(screen.getByText('Sold out')).toBeOnTheScreen();
  });

  it.each([
    [47, 'باقي 47 قطعة لتأكيد الصفقة'],
    [5, 'باقي 5 قطع لتأكيد الصفقة'],
    [2, 'باقي قطعتان لتأكيد الصفقة'],
    [1, 'باقي قطعة واحدة لتأكيد الصفقة'],
  ])('uses the right Arabic plural for %i', async (n, text) => {
    await inLanguage('ar');
    await render(<CampaignProgress progress={progress({ units_to_target: n })} />);
    expect(screen.getByText(text)).toBeOnTheScreen();
  });
});

describe('TierLadder', () => {
  it('lists every tier with whole-riyal prices and marks the current one', async () => {
    const { tiers } = campaignDetail('en');
    await render(<TierLadder tiers={tiers} committedQty={53} />);
    expect(screen.getByText('1–49 units')).toBeOnTheScreen();
    expect(screen.getByText('100–150 units')).toBeOnTheScreen();
    expect(screen.getByText(`${LRI}79${PDI} SAR`)).toBeOnTheScreen();
    expect(screen.getByText(`${LRI}60${PDI} SAR`)).toBeOnTheScreen();
    expect(screen.getAllByText('Now')).toHaveLength(1);
  });
});
