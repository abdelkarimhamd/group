import { expect, type Page } from "@playwright/test";
import { ar } from "../src/i18n/dictionaries/ar";

export { ar };

export const KETTLE = "0b6f8f2e-6a43-4f7e-9d0e-1a2b3c4d5e01";
export const OTP = "123456";

let counter = 0;

/** A fresh Saudi mobile number, so every test signs up a new customer. */
export function newPhone(): string {
  counter += 1;
  const tail = `${Date.now() % 1_000_000}${counter}`.padStart(8, "0").slice(-8);
  return `05${tail}`;
}

/** Signs in from the current sign-in page; a new customer lands on the welcome page. */
export async function signIn(page: Page, phone = newPhone(), name: string | null = "نورة") {
  await page.getByLabel(ar.auth.phoneLabel).fill(phone);
  await page.getByRole("button", { name: ar.auth.sendCode }).click();
  await page.getByLabel(ar.auth.codeLabel).fill(OTP);
  await expect(page).toHaveURL(/\/account\/welcome/);
  if (name) {
    await page.getByLabel(ar.account.name).fill(name);
    await page.getByRole("button", { name: ar.common.continue }).click();
  } else {
    await page.getByRole("link", { name: ar.auth.skip }).click();
  }
  await expect(page).not.toHaveURL(/\/account\/welcome/);
  return phone;
}
