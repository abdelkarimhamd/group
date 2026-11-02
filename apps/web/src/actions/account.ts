"use server";

import { refresh } from "next/cache";
import { isLocale, type Locale } from "@/i18n/config";
import { createApi, unwrap } from "@/lib/api/server";
import { toActionFailure, type ActionFailure } from "@/lib/api/errors";
import type { Address, AddressInput, User } from "@/lib/api/types";
import { getToken } from "@/lib/session";

async function authed(localeValue: string) {
  const locale: Locale = isLocale(localeValue) ? localeValue : "ar";
  const token = await getToken();
  if (!token) return { locale, api: null };
  return { locale, api: createApi(locale, token) };
}

const signedOut: ActionFailure = { ok: false, status: 401, code: "unauthenticated" };

export async function updateProfile(input: {
  locale: string;
  name?: string | null;
  email?: string | null;
  preferredLocale?: Locale;
}): Promise<{ ok: true; user: User } | ActionFailure> {
  const { api } = await authed(input.locale);
  if (!api) return signedOut;
  const body: { name?: string | null; email?: string | null; locale?: Locale } = {};
  if (input.name !== undefined) body.name = input.name?.trim() || null;
  if (input.email !== undefined) body.email = input.email?.trim() || null;
  if (input.preferredLocale) body.locale = input.preferredLocale;
  try {
    const { data } = await unwrap(api.PATCH("/me", { body }));
    refresh();
    return { ok: true, user: data };
  } catch (error) {
    return toActionFailure(error);
  }
}

export async function acceptPrivacy(input: {
  locale: string;
  policyVersion: string;
}): Promise<{ ok: true; user: User } | ActionFailure> {
  const { api } = await authed(input.locale);
  if (!api) return signedOut;
  try {
    const { data } = await unwrap(
      api.POST("/me/privacy-consent", { body: { policy_version: input.policyVersion } }),
    );
    return { ok: true, user: data };
  } catch (error) {
    return toActionFailure(error);
  }
}

export async function saveAddress(input: {
  locale: string;
  id?: string;
  address: AddressInput;
}): Promise<{ ok: true; address: Address } | ActionFailure> {
  const { api } = await authed(input.locale);
  if (!api) return signedOut;
  try {
    const { data } = input.id
      ? await unwrap(
          api.PATCH("/me/addresses/{address_id}", {
            params: { path: { address_id: input.id } },
            body: input.address,
          }),
        )
      : await unwrap(api.POST("/me/addresses", { body: input.address }));
    refresh();
    return { ok: true, address: data };
  } catch (error) {
    return toActionFailure(error);
  }
}

export async function deleteAddress(input: { locale: string; id: string }): Promise<{ ok: true } | ActionFailure> {
  const { api } = await authed(input.locale);
  if (!api) return signedOut;
  try {
    await unwrap(api.DELETE("/me/addresses/{address_id}", { params: { path: { address_id: input.id } } }));
    refresh();
    return { ok: true };
  } catch (error) {
    return toActionFailure(error);
  }
}
