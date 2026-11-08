import { appPathForWebsiteLink, campaignUrl, webPageUrl } from '../links';

const deal = '0b8f0e2c-1c55-4a8b-9a0e-6f4f8d1a0001';
const order = 'e1a2b3c4-d5e6-4f70-8a9b-0c1d2e000001';
const join = 'c1a2b3c4-d5e6-4f70-8a9b-0c1d2e000001';

describe('website links', () => {
  it('builds the pages the app opens and shares', () => {
    expect(webPageUrl('https://example.sa', 'ar', 'terms')).toBe('https://example.sa/ar/terms');
    expect(campaignUrl('https://example.sa', 'en', deal)).toBe(
      `https://example.sa/en/campaigns/${deal}`,
    );
  });

  it('open the matching screen in the app', () => {
    expect(appPathForWebsiteLink(`https://example.sa/ar/campaigns/${deal}`)).toBe(
      `/campaign/${deal}`,
    );
    expect(appPathForWebsiteLink(`/en/campaigns/${deal}/join?qty=2`)).toBe(`/campaign/${deal}`);
    expect(appPathForWebsiteLink(`/ar/account/orders/${order}`)).toBe(`/orders/${order}`);
    expect(appPathForWebsiteLink(`/ar/account/joins/${join.toUpperCase()}`)).toBe(`/joins/${join}`);
    expect(appPathForWebsiteLink('/en/account/orders')).toBe('/orders');
    expect(appPathForWebsiteLink('/en/account/joins/')).toBe('/joins');
    expect(appPathForWebsiteLink('/ar/account')).toBe('/account');
    expect(appPathForWebsiteLink('/ar/how-it-works')).toBe('/how-it-works');
    expect(appPathForWebsiteLink('https://example.sa/en')).toBe('/');
  });

  it('send website pages the app has no screen for to the deals list', () => {
    expect(appPathForWebsiteLink('/ar/terms')).toBe('/');
    expect(appPathForWebsiteLink('/en/campaigns/not-an-id')).toBe('/');
  });

  it("leave the app's own links alone", () => {
    expect(appPathForWebsiteLink(`/campaign/${deal}`)).toBeNull();
    expect(appPathForWebsiteLink(`groupbuy://orders/${order}`)).toBeNull();
    expect(appPathForWebsiteLink('/')).toBeNull();
    expect(appPathForWebsiteLink('/articles/2026')).toBeNull();
  });
});
