"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useRef, useState, useTransition } from "react";
import { requestOtp, verifyOtp } from "@/actions/auth";
import { useI18n } from "@/i18n/client";
import { formatNumber, interpolate } from "@/i18n/format";
import { useNow } from "@/hooks/use-now";
import { digitsOnly, formatSaudiMobile, normalizeSaudiMobile } from "@/lib/phone";
import { safeNextPath } from "@/lib/paths";
import type { OtpChallenge } from "@/lib/api/types";
import { Alert, Button, Field, Input, Spinner } from "./ui";

type Step = { kind: "phone" } | { kind: "code"; phone: string; challenge: OtpChallenge };

export function SignInForm() {
  const { t, locale } = useI18n();
  const router = useRouter();
  const search = useSearchParams();
  const next = safeNextPath(search.get("next"), locale);
  const expired = search.get("expired") === "1";

  const [step, setStep] = useState<Step>({ kind: "phone" });
  const [phone, setPhone] = useState("");
  const [code, setCode] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();
  const codeRef = useRef<HTMLInputElement>(null);
  const autoSubmitted = useRef<string | null>(null);
  const now = useNow();

  useEffect(() => {
    if (step.kind === "code") codeRef.current?.focus();
  }, [step]);

  function failureMessage(failure: { status: number; code?: string; retryAfter?: number }): string {
    if (failure.status === 429) {
      return interpolate(t.auth.errors.rateLimited, { seconds: formatNumber(failure.retryAfter ?? 60, locale) });
    }
    if (failure.code === "otp_invalid" || failure.code === "otp_expired" || failure.code === "otp_attempts_exhausted") {
      return t.auth.errors[failure.code];
    }
    if (failure.code === "phone_invalid" || (failure.status === 422 && step.kind === "phone")) return t.auth.phoneInvalid;
    if (failure.status === 0) return t.common.networkError;
    return t.common.genericError;
  }

  function sendCode() {
    setError(null);
    if (!normalizeSaudiMobile(phone)) {
      setError(t.auth.phoneInvalid);
      return;
    }
    startTransition(async () => {
      const result = await requestOtp({ locale, phone });
      if (result.ok) {
        setCode("");
        setStep({ kind: "code", phone: result.phone, challenge: result.challenge });
      } else {
        setError(failureMessage(result));
      }
    });
  }

  function verify(value: string) {
    if (step.kind !== "code") return;
    setError(null);
    if (value.length !== 6) {
      setError(t.auth.codeInvalid);
      return;
    }
    startTransition(async () => {
      const result = await verifyOtp({ locale, challengeId: step.challenge.challenge_id, code: value });
      if (result.ok) {
        const target = result.isNewUser && !result.hasName ? `/${locale}/account/welcome?next=${encodeURIComponent(next)}` : next;
        router.replace(target);
        router.refresh();
      } else {
        setError(failureMessage(result));
        if (result.code === "otp_expired" || result.code === "otp_attempts_exhausted") setCode("");
      }
    });
  }

  if (step.kind === "phone") {
    return (
      <form
        noValidate
        onSubmit={(event) => {
          event.preventDefault();
          sendCode();
        }}
        className="space-y-5"
      >
        {expired ? <Alert tone="warning">{t.auth.expired}</Alert> : null}
        <Field label={t.auth.phoneLabel} hint={t.auth.phoneHint} error={error} htmlFor="phone">
          <Input
            id="phone"
            name="phone"
            type="tel"
            inputMode="tel"
            autoComplete="tel"
            dir="ltr"
            placeholder="05x xxx xxxx"
            value={phone}
            onChange={(event) => setPhone(event.target.value)}
            aria-invalid={Boolean(error)}
            aria-describedby={error ? "phone-error" : "phone-hint"}
            className="text-start text-lg tracking-wide rtl:text-end"
          />
        </Field>
        <Button type="submit" className="w-full" disabled={pending}>
          {pending ? <Spinner /> : null}
          {pending ? t.auth.sending : t.auth.sendCode}
        </Button>
        <p className="text-center text-xs leading-relaxed text-slate-500">
          {(() => {
            const [before, rest] = t.auth.agree.split("{terms}");
            const [middle, after] = (rest ?? "").split("{privacy}");
            return (
              <>
                {before}
                <Link href={`/${locale}/terms`} className="underline">
                  {t.footer.terms}
                </Link>
                {middle}
                <Link href={`/${locale}/privacy`} className="underline">
                  {t.footer.privacy}
                </Link>
                {after}
              </>
            );
          })()}
        </p>
      </form>
    );
  }

  const resendAt = Date.parse(step.challenge.resend_available_at);
  const secondsLeft = now === null ? null : Math.max(0, Math.ceil((resendAt - now) / 1000));

  return (
    <form
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        verify(code);
      }}
      className="space-y-5"
    >
      <div>
        <h2 className="text-lg font-semibold">{t.auth.codeTitle}</h2>
        <p className="mt-1 text-sm text-slate-600">
          {interpolate(t.auth.codeSent, { phone: "⁦" + formatSaudiMobile(step.phone) + "⁩" })}
        </p>
      </div>
      <Field label={t.auth.codeLabel} error={error} htmlFor="code">
        <Input
          ref={codeRef}
          id="code"
          name="code"
          inputMode="numeric"
          autoComplete="one-time-code"
          dir="ltr"
          maxLength={6}
          value={code}
          onChange={(event) => {
            const value = digitsOnly(event.target.value, 6);
            setCode(value);
            // Auto-submit once per complete code. Not gated on `pending`: an
            // autofilled code can arrive while the send-code transition is
            // still settling, and would otherwise be ignored.
            if (value.length === 6 && autoSubmitted.current !== value) {
              autoSubmitted.current = value;
              verify(value);
            }
          }}
          aria-invalid={Boolean(error)}
          aria-describedby={error ? "code-error" : undefined}
          className="text-center text-2xl font-semibold tracking-[0.5em]"
        />
      </Field>
      <Button type="submit" className="w-full" disabled={pending}>
        {pending ? <Spinner /> : null}
        {pending ? t.auth.verifying : t.auth.verify}
      </Button>
      <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
        <button
          type="button"
          className="font-medium text-brand-700 hover:underline"
          onClick={() => {
            setError(null);
            setCode("");
            setStep({ kind: "phone" });
          }}
        >
          {t.auth.changeNumber}
        </button>
        {secondsLeft !== null && secondsLeft > 0 ? (
          <span className="text-slate-500">
            {interpolate(t.auth.resendIn, { seconds: formatNumber(secondsLeft, locale) })}
          </span>
        ) : (
          <button
            type="button"
            className="font-medium text-brand-700 hover:underline disabled:text-slate-400"
            disabled={pending}
            onClick={sendCode}
          >
            {t.auth.resend}
          </button>
        )}
      </div>
    </form>
  );
}
