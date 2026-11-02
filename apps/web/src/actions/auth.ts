"use server";

import { headers } from "next/headers";
import { redirect } from "next/navigation";
import { isLocale, type Locale } from "@/i18n/config";
import { createApi, unwrap } from "@/lib/api/server";
import { toActionFailure } from "@/lib/api/errors";
import { normalizeSaudiMobile, digitsOnly } from "@/lib/phone";
import { deviceNameFrom } from "@/lib/device";
import { clearSession, getToken, setSession } from "@/lib/session";

function localeOf(value: string): Locale {
  return isLocale(value) ? value : "ar";
}

/** Step 1: send an SMS code. */
export async function requestOtp(input: { locale: string; phone: string }) {
  const locale = localeOf(input.locale);
  const phone = normalizeSaudiMobile(input.phone);
  if (!phone) return { ok: false as const, status: 422, code: "phone_invalid" };
  try {
    const { data } = await unwrap(createApi(locale).POST("/auth/otp", { body: { phone } }));
    return { ok: true as const, phone, challenge: data };
  } catch (error) {
    return toActionFailure(error);
  }
}

/** Step 2: trade the code for a session cookie. */
export async function verifyOtp(input: { locale: string; challengeId: string; code: string }) {
  const locale = localeOf(input.locale);
  const code = digitsOnly(input.code, 6);
  if (code.length !== 6) return { ok: false as const, status: 422, code: "otp_invalid" };
  try {
    const userAgent = (await headers()).get("user-agent");
    const { data } = await unwrap(
      createApi(locale).POST("/auth/otp/verify", {
        body: { challenge_id: input.challengeId, code, device_name: deviceNameFrom(userAgent) },
      }),
    );
    await setSession(data.token);
    return { ok: true as const, isNewUser: data.is_new_user, hasName: Boolean(data.user.name) };
  } catch (error) {
    return toActionFailure(error);
  }
}

export async function signOut(formData: FormData) {
  const locale = localeOf(String(formData.get("locale") ?? ""));
  const token = await getToken();
  if (token) {
    try {
      await unwrap(createApi(locale, token).POST("/auth/logout"));
    } catch {
      // The token may already be gone; the cookie is cleared either way.
    }
  }
  await clearSession();
  redirect(`/${locale}`);
}
