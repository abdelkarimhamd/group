import { defineConfig, devices } from "@playwright/test";
import path from "node:path";

// Browser tests run the production build against the mock stack: the
// fixture API behind Prism, which checks every request against the
// contract. Build first with API_BASE_URL=http://127.0.0.1:4010 and
// NEXT_PUBLIC_MOYASAR_PUBLISHABLE_KEY=pk_test_e2e (the app payment page's tests).
const WEB_PORT = Number(process.env.E2E_WEB_PORT ?? 3100);
const baseURL = `http://127.0.0.1:${WEB_PORT}`;
export const VIOLATIONS_FILE = path.resolve(__dirname, ".mock/contract-violations.log");

export default defineConfig({
  testDir: "./e2e",
  workers: 1,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [["github"], ["html", { open: "never" }]] : "list",
  globalTeardown: "./e2e/global-teardown.ts",
  use: {
    baseURL,
    locale: "ar-SA",
    timezoneId: "Asia/Riyadh",
    trace: "retain-on-failure",
  },
  projects: [
    { name: "desktop", use: { ...devices["Desktop Chrome"], locale: "ar-SA" } },
    { name: "mobile", use: { ...devices["Pixel 7"], locale: "ar-SA" } },
  ],
  webServer: [
    {
      command: "node scripts/mock.mjs",
      url: "http://127.0.0.1:4010/health",
      env: { MOCK_SIMULATE: "0", MOCK_WEB_URL: baseURL, MOCK_VIOLATIONS_FILE: VIOLATIONS_FILE },
      reuseExistingServer: !process.env.CI,
      stdout: "ignore",
    },
    {
      command: `npx next start -H 127.0.0.1 -p ${WEB_PORT}`,
      url: `${baseURL}/ar/how-it-works`,
      env: { API_BASE_URL: "http://127.0.0.1:4010", NEXT_PUBLIC_SITE_URL: baseURL },
      reuseExistingServer: !process.env.CI,
    },
  ],
});
