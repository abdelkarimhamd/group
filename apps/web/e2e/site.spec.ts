import { expect, test } from "@playwright/test";
import { ar, KETTLE, signIn } from "./helpers";
import { en } from "../src/i18n/dictionaries/en";

test.describe("browsing", () => {
  test("home is Arabic and right to left, and switches to English", async ({ page }) => {
    await page.goto("/");
    await expect(page).toHaveURL(/\/ar$/);
    await expect(page.locator("html")).toHaveAttribute("dir", "rtl");
    await expect(page.locator("html")).toHaveAttribute("lang", "ar");
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(ar.home.heroTitle);
    await expect(page.locator('a[href^="/ar/campaigns/"]').first()).toBeVisible();

    await page.getByRole("link", { name: ar.nav.switchLanguageLabel }).click();
    await expect(page).toHaveURL(/\/en$/);
    await expect(page.locator("html")).toHaveAttribute("dir", "ltr");
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(en.home.heroTitle);
  });

  test("campaign page shows the price, live progress and tiers", async ({ page }) => {
    await page.goto(`/ar/campaigns/${KETTLE}`);
    await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    await expect(page.getByRole("progressbar").first()).toBeVisible();
    await expect(page.getByRole("heading", { name: ar.campaign.tiersTitle }).first()).toBeVisible();
    const unit = Number((await page.getByTestId("hold-estimate").innerText()).replace(/[^\d]/g, ""));
    expect(unit).toBeGreaterThan(0);
    await page.getByRole("button", { name: ar.campaign.increase }).click();
    await expect(page.getByTestId("hold-estimate")).toContainText(String(unit * 2));
  });

  test("how it works and the legal drafts render", async ({ page }) => {
    await page.goto("/ar/how-it-works");
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(ar.how.title);
    await page.getByText(ar.how.faq[0]!.q).click();
    await expect(page.getByText(ar.how.faq[0]!.a)).toBeVisible();
    for (const path of ["privacy", "terms", "returns"] as const) {
      await page.goto(`/ar/${path}`);
      await expect(page.getByRole("heading", { level: 1 })).toHaveText(ar.legal[`${path}Title`]);
      await expect(page.getByText(ar.common.draft)).toBeVisible();
    }
  });
});

test("Apple Pay's domain file is served as it is, never moved under a locale", async ({ request }) => {
  const response = await request.get("/.well-known/apple-developer-merchantid-domain-association", { maxRedirects: 0 });
  // Not deployed in tests (it comes from Moyasar's dashboard), so 404, but never a redirect to /ar/.
  expect(response.status()).toBe(404);
});

test.describe("the app's payment page", () => {
  const PAYMENT_ID = "0198f5a2-0000-7000-8000-0000000000aa";
  const checkout = (origin: string) => ({
    publishable_api_key: "pk_test_e2e",
    amount: 13_800,
    currency: "SAR",
    description: "GB-2611-0007 x2",
    callback_url: `${origin}/payments/return?payment_id=${PAYMENT_ID}`,
    methods: ["creditcard", "applepay"],
    supported_networks: ["mada", "visa", "mastercard"],
    manual: true,
    metadata: { payment_id: PAYMENT_ID },
  });
  const pageFor = (value: unknown) => `/ar/payments/app#${encodeURIComponent(JSON.stringify(value))}`;
  // What react-native-webview gives the page on Android; messages reach the test through __toApp.
  const asTheApp = () => {
    const w = window as unknown as { __toApp?: (message: string) => void; ReactNativeWebView: unknown };
    w.ReactNativeWebView = { postMessage: (message: string) => w.__toApp?.(message) };
  };

  test("opened in a browser, it shows no payment form", async ({ page, baseURL }) => {
    await page.goto(pageFor(checkout(baseURL!)));
    await expect(page.getByTestId("app-payment")).toHaveAttribute("data-state", "app-only");
    await expect(page.getByText(ar.payment.appOnly)).toBeVisible();
    await expect(page.getByTestId("moyasar-form")).toHaveCount(0);
  });

  test("inside the app, a checkout for another site is refused", async ({ page }) => {
    await page.addInitScript(asTheApp);
    await page.goto(pageFor(checkout("https://evil.example")));
    await expect(page.getByTestId("app-payment")).toHaveAttribute("data-state", "invalid");
    await expect(page.getByText(ar.payment.appInvalid)).toBeVisible();
  });

  test("inside the app, the card form asks Moyasar for a hold and tells the app its ID", async ({ page, baseURL }) => {
    const toApp: unknown[] = [];
    await page.exposeFunction("__toApp", (message: string) => toApp.push(JSON.parse(message)));
    await page.addInitScript(asTheApp);
    await page.route("https://applepay.cdn-apple.com/**", (route) => route.abort());
    const threeDs = `${baseURL}/ar/how-it-works?three_ds=1`;
    let sent: Record<string, unknown> | null = null;
    await page.route("https://api.moyasar.com/v1/payments", async (route) => {
      sent = route.request().postDataJSON();
      await route.fulfill({
        status: 201,
        contentType: "application/json",
        body: JSON.stringify({
          id: "pay_e2e",
          status: "initiated",
          amount: 13_800,
          currency: "SAR",
          source: { type: "creditcard", transaction_url: threeDs, message: null },
        }),
      });
    });

    await page.goto(pageFor(checkout(baseURL!)));
    await expect(page.getByTestId("moyasar-form")).toHaveAttribute("data-state", "ready");
    // The form formats as it goes, so type like a person would.
    await page.locator('input[name="mysr-cc-name"]').pressSequentially("Test Customer");
    await page.locator('input[name="mysr-cc-number"]').pressSequentially("4111111111111111");
    await page.locator('input[name="mysr-cc-exp"]').pressSequentially("1230");
    await page.locator('input[name="mysr-cc-csc"]').pressSequentially("123");
    await page.locator('[data-testid="moyasar-form"] button[type="submit"]').click();

    // The form follows Moyasar's 3-D Secure link only after the app has the ID.
    await expect(page).toHaveURL(threeDs);
    expect(toApp).toEqual([{ type: "moyasar_payment", id: "pay_e2e" }]);
    expect(sent).toMatchObject({
      publishable_api_key: "pk_test_e2e",
      amount: 13_800,
      currency: "SAR",
      callback_url: `${baseURL}/payments/return?payment_id=${PAYMENT_ID}`,
      metadata: { payment_id: PAYMENT_ID },
      source: { type: "creditcard", manual: true },
    });
  });
});

test.describe("joining", () => {
  test("sign in, join with a fake card hold, then cancel", async ({ page }) => {
    await page.goto(`/ar/campaigns/${KETTLE}`);
    await page.getByRole("button", { name: ar.campaign.increase }).click();
    await page.getByRole("link", { name: ar.campaign.join }).click();

    // Signed out: the join page asks for sign-in first, then comes back.
    await expect(page).toHaveURL(/\/ar\/sign-in\?next=/);
    await signIn(page);
    await expect(page).toHaveURL(new RegExp(`/ar/campaigns/${KETTLE}/join`));
    await expect(page.getByTestId("quantity").first()).toHaveText("2");

    // New customers get a seeded Jeddah address in the mock; it is preselected.
    await expect(page.getByRole("radio", { name: /جدة/ })).toBeChecked();

    // Privacy consent is required on the first join.
    await page.getByTestId("confirm-join").click();
    await expect(page.getByText(ar.join.privacyRequired)).toBeVisible();
    await page.getByTestId("privacy-consent").check();
    await page.getByTestId("confirm-join").click();

    const paymentStep = page.getByTestId("payment-step");
    await expect(paymentStep).toBeVisible();
    await paymentStep.getByRole("link", { name: ar.payment.fakeApprove }).click();

    await expect(page.getByTestId("payment-return")).toHaveAttribute("data-state", "success");
    await expect(page.getByRole("heading", { name: ar.payment.successTitle })).toBeVisible();
    await page.getByRole("link", { name: ar.payment.viewJoin }).click();

    await expect(page.getByTestId("join-status-help")).toHaveText(ar.joins.statusHelp.authorized);
    await page.getByTestId("cancel-join").click();
    await page.getByTestId("confirm-cancel").click();
    await expect(page.getByText(ar.joins.cancelled)).toBeVisible();
    await expect(page.getByTestId("join-status-help")).toHaveText(ar.joins.statusHelp.cancelled);
  });

  test("a declined card can be retried with another card", async ({ page }) => {
    await page.goto(`/ar/campaigns/${KETTLE}/join`);
    await signIn(page, undefined, null);
    await page.getByTestId("privacy-consent").check();
    await page.getByTestId("confirm-join").click();
    await page.getByTestId("payment-step").getByRole("link", { name: ar.payment.fakeDecline }).click();

    await expect(page.getByTestId("payment-return")).toHaveAttribute("data-state", "failed");
    await expect(page.getByText(ar.payment.reasons.declined)).toBeVisible();
    await page.getByRole("button", { name: ar.payment.tryAnotherCard }).click();
    await page.getByTestId("payment-step").getByRole("link", { name: ar.payment.fakeApprove }).click();
    await expect(page.getByTestId("payment-return")).toHaveAttribute("data-state", "success");
  });

  test("on the join's page, another card opens a form that stays open", async ({ page }) => {
    await page.goto(`/ar/campaigns/${KETTLE}/join`);
    await signIn(page, undefined, null);
    await page.getByTestId("privacy-consent").check();
    await page.getByTestId("confirm-join").click();
    await page.getByTestId("payment-step").getByRole("link", { name: ar.payment.fakeDecline }).click();
    await expect(page.getByTestId("payment-return")).toHaveAttribute("data-state", "failed");
    await page.getByRole("link", { name: ar.payment.viewJoin }).click();

    const actions = page.getByTestId("join-actions");
    await actions.getByRole("button", { name: ar.joins.retryPayment }).click();
    // Scoped: Next keeps the earlier join page, with its own form, hidden in the tree.
    const step = actions.getByTestId("payment-step");
    await expect(step).toBeVisible();
    // Starting the attempt refreshes the page's data; the form must survive it.
    await page.waitForLoadState("networkidle");
    await expect(step).toBeVisible();
    await expect(actions.getByRole("button", { name: ar.joins.continuePayment })).toHaveCount(0);
    await step.getByRole("link", { name: ar.payment.fakeApprove }).click();
    await expect(page.getByTestId("payment-return")).toHaveAttribute("data-state", "success");
  });

  test("the return page asks again when a check does not reach the site", async ({ page }) => {
    await page.goto(`/ar/campaigns/${KETTLE}/join`);
    await signIn(page, undefined, null);
    await page.getByTestId("privacy-consent").check();
    await page.getByTestId("confirm-join").click();

    // The first check (a server action posted to the page) is lost on the way.
    let dropped = 0;
    await page.route(/\/payments\/return/, (route) => {
      const request = route.request();
      if (request.method() === "POST" && request.headers()["next-action"] && dropped === 0) {
        dropped += 1;
        return route.abort("internetdisconnected");
      }
      return route.continue();
    });
    await page.getByTestId("payment-step").getByRole("link", { name: ar.payment.fakeApprove }).click();

    await expect(page.getByTestId("payment-return")).toHaveAttribute("data-state", "success", { timeout: 15_000 });
    expect(dropped).toBe(1);
  });
});

test.describe("account", () => {
  test("joins, orders with tracking, and addresses", async ({ page }) => {
    await page.goto("/ar/account");
    await expect(page).toHaveURL(/\/ar\/sign-in/);
    await signIn(page, undefined, "سارة");
    await expect(page).toHaveURL(/\/ar\/account$/);
    await expect(page.getByTestId("account-greeting")).toHaveText("أهلًا سارة");

    // The mock gives every new customer a delivered order and a released join.
    await page.goto("/ar/account/joins");
    await expect(page.getByTestId("join-row")).toHaveCount(2);
    await page.goto("/ar/account/orders");
    await page.getByTestId("order-row").first().click();
    await expect(page.getByTestId("shipment-events").locator("li").first()).toContainText(ar.orders.shipmentStatus.delivered);
    await expect(page.getByText(ar.orders.invoiceType.simplified_tax_invoice)).toBeVisible();

    await page.goto("/ar/account/addresses");
    await expect(page.getByTestId("address-card")).toHaveCount(1);
    await page.getByTestId("add-address").click();
    const form = page.getByTestId("address-form");
    await form.getByLabel(ar.addresses.fields.city).fill("الرياض");
    await form.getByLabel(ar.addresses.fields.district).fill("العليا");
    await form.getByLabel(ar.addresses.fields.street).fill("طريق الملك فهد");
    await form.getByLabel(ar.addresses.fields.building_no).fill("2401");
    await form.getByLabel(ar.addresses.fields.postal_code).fill("123");
    await form.getByRole("button", { name: ar.common.save }).click();
    await expect(form.getByText(ar.addresses.invalid.postal_code)).toBeVisible();
    await form.getByLabel(ar.addresses.fields.postal_code).fill("12211");
    await form.getByRole("button", { name: ar.common.save }).click();
    await expect(page.getByText(ar.addresses.saved)).toBeVisible();
    await expect(page.getByTestId("address-card")).toHaveCount(2);

    const riyadh = page.getByTestId("address-card").filter({ hasText: "الرياض" });
    await riyadh.getByRole("button", { name: ar.addresses.makeDefault }).click();
    await expect(riyadh.getByText(ar.addresses.default)).toBeVisible();

    const jeddah = page.getByTestId("address-card").filter({ hasText: "جدة" });
    await jeddah.getByRole("button", { name: ar.common.delete }).click();
    await jeddah.getByRole("button", { name: ar.common.delete }).click();
    await expect(page.getByTestId("address-card")).toHaveCount(1);
  });
});
