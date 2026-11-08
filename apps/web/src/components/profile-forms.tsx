"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useState, useTransition } from "react";
import { acceptPrivacy, deleteAccount, updateProfile, type DeletionBlocker } from "@/actions/account";
import { useI18n } from "@/i18n/client";
import { formatDate, interpolate, ltr } from "@/i18n/format";
import { locales, type Locale } from "@/i18n/config";
import { formatSaudiMobile } from "@/lib/phone";
import { safeNextPath } from "@/lib/paths";
import type { User } from "@/lib/api/types";
import { Alert, Button, ButtonLink, Field, Input, Spinner, cx } from "./ui";

// Loose check before the API's strict one; the API's 422 on `email` wins.
const EMAIL = /^[A-Za-z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/;

export function ProfileForm({ user }: { user: User }) {
  const { t, locale } = useI18n();
  const [name, setName] = useState(user.name ?? "");
  const [email, setEmail] = useState(user.email ?? "");
  const [language, setLanguage] = useState<Locale>(user.locale);
  const [emailError, setEmailError] = useState<string | null>(null);
  const [status, setStatus] = useState<{ tone: "success" | "danger"; text: string } | null>(null);
  const [pending, startTransition] = useTransition();

  function submit(event: React.FormEvent) {
    event.preventDefault();
    setStatus(null);
    const trimmedEmail = email.trim();
    if (trimmedEmail && !EMAIL.test(trimmedEmail)) {
      setEmailError(t.account.emailInvalid);
      return;
    }
    setEmailError(null);
    startTransition(async () => {
      const result = await updateProfile({ locale, name, email: trimmedEmail, preferredLocale: language });
      if (result.ok) {
        setStatus({ tone: "success", text: t.account.saved });
        return;
      }
      if (result.fieldErrors?.email) {
        setEmailError(t.account.emailInvalid);
        return;
      }
      setStatus({ tone: "danger", text: result.status === 0 ? t.common.networkError : t.common.genericError });
    });
  }

  return (
    <form onSubmit={submit} noValidate className="space-y-4" data-testid="profile-form">
      <Field label={t.account.name} htmlFor="profile-name">
        <Input id="profile-name" value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" maxLength={100} disabled={pending} />
      </Field>
      <Field
        label={t.account.email}
        htmlFor="profile-email"
        optionalLabel={t.common.optional}
        error={emailError}
      >
        <Input
          id="profile-email"
          type="email"
          dir="ltr"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          autoComplete="email"
          aria-invalid={emailError ? true : undefined}
          aria-describedby={emailError ? "profile-email-error" : undefined}
          disabled={pending}
        />
      </Field>
      <Field label={t.account.phone} htmlFor="profile-phone" hint={t.account.phoneLocked}>
        <Input id="profile-phone" dir="ltr" value={formatSaudiMobile(user.phone)} readOnly disabled aria-describedby="profile-phone-hint" />
      </Field>
      <fieldset>
        <legend className="text-sm font-medium text-slate-800">{t.account.language}</legend>
        <p className="text-xs text-slate-500">{t.account.languageHint}</p>
        <div className="mt-2 flex gap-2">
          {locales.map((option) => (
            <label
              key={option}
              className={cx(
                "inline-flex min-h-10 cursor-pointer items-center rounded-xl px-4 text-sm font-medium ring-1 has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-600",
                option === language ? "bg-brand-700 text-white ring-brand-700" : "bg-white text-slate-700 ring-slate-300",
              )}
            >
              <input
                type="radio"
                name="language"
                value={option}
                checked={option === language}
                onChange={() => setLanguage(option)}
                className="sr-only"
              />
              <span lang={option}>{t.account.languages[option]}</span>
            </label>
          ))}
        </div>
      </fieldset>
      {status ? <Alert tone={status.tone}>{status.text}</Alert> : null}
      <Button type="submit" disabled={pending}>
        {pending ? <Spinner /> : null}
        {pending ? t.common.saving : t.common.save}
      </Button>
    </form>
  );
}

export function PrivacyStatus({ user }: { user: User }) {
  const { t, locale } = useI18n();
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();
  const consent = user.privacy_consent;
  const current = consent?.policy_version === user.current_privacy_policy_version;

  function accept() {
    setError(null);
    startTransition(async () => {
      const result = await acceptPrivacy({ locale, policyVersion: user.current_privacy_policy_version });
      if (result.ok) router.refresh();
      else setError(result.status === 0 ? t.common.networkError : t.common.genericError);
    });
  }

  return (
    <div className="space-y-3 text-sm text-slate-700" data-testid="privacy-status">
      {consent ? (
        <p>
          {interpolate(t.account.privacyAccepted, {
            version: ltr(consent.policy_version),
            date: formatDate(consent.accepted_at, locale, "date"),
          })}
        </p>
      ) : (
        <p>{t.account.privacyNotAccepted}</p>
      )}
      {consent && !current ? <Alert tone="warning">{t.account.privacyOutdated}</Alert> : null}
      <div className="flex flex-wrap items-center gap-3">
        <Link href={`/${locale}/privacy`} className="font-medium text-brand-700 hover:underline">
          {t.footer.privacy}
        </Link>
        {!current ? (
          <Button variant="secondary" onClick={accept} disabled={pending}>
            {pending ? <Spinner /> : null}
            {t.account.acceptPrivacy}
          </Button>
        ) : null}
      </div>
      {error ? <Alert tone="danger">{error}</Alert> : null}
    </div>
  );
}

/** Shown once after the first sign-in: ask for a name, then carry on. */
export function WelcomeForm() {
  const { t, locale } = useI18n();
  const router = useRouter();
  const search = useSearchParams();
  const next = safeNextPath(search.get("next"), locale);
  const [name, setName] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!name.trim()) {
      router.replace(next);
      return;
    }
    setError(null);
    startTransition(async () => {
      const result = await updateProfile({ locale, name });
      if (result.ok) router.replace(next);
      else setError(result.status === 0 ? t.common.networkError : t.common.genericError);
    });
  }

  return (
    <form onSubmit={submit} className="space-y-4" data-testid="welcome-form">
      <Field label={t.account.name} htmlFor="welcome-name">
        <Input
          id="welcome-name"
          value={name}
          onChange={(event) => setName(event.target.value)}
          autoComplete="name"
          maxLength={100}
          autoFocus
          disabled={pending}
        />
      </Field>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <div className="flex flex-wrap gap-3">
        <Button type="submit" disabled={pending}>
          {pending ? <Spinner /> : null}
          {t.common.continue}
        </Button>
        <ButtonLink href={next} variant="ghost">
          {t.auth.skip}
        </ButtonLink>
      </div>
    </form>
  );
}

/**
 * Account deletion (PDPL erasure). The app stores ask for a web page where
 * customers can delete their account; this is it, at /{locale}/account.
 */
export function DeleteAccount() {
  const { t, locale } = useI18n();
  const [confirming, setConfirming] = useState(false);
  const [blockedBy, setBlockedBy] = useState<DeletionBlocker[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [deleted, setDeleted] = useState(false);
  const [pending, startTransition] = useTransition();

  function remove() {
    setError(null);
    setBlockedBy(null);
    startTransition(async () => {
      const result = await deleteAccount({ locale });
      setConfirming(false);
      if (result.ok) setDeleted(true);
      else if (result.blockedBy) setBlockedBy(result.blockedBy);
      else setError(result.status === 0 ? t.common.networkError : t.common.genericError);
    });
  }

  if (deleted) {
    return (
      <div className="space-y-3" data-testid="account-deleted">
        <Alert tone="success">{t.account.deleteDone}</Alert>
        {/* A full page load, so the header sees the session is gone. */}
        <a href={`/${locale}`} className="inline-block text-sm font-medium text-brand-700 hover:underline">
          {t.account.deleteHome}
        </a>
      </div>
    );
  }

  return (
    <div className="space-y-3 text-sm text-slate-700" data-testid="delete-account">
      <p>{t.account.deleteErased}</p>
      <p className="text-slate-500">{t.account.deleteKept}</p>
      {blockedBy ? (
        <Alert tone="warning">
          <p className="font-semibold">{t.account.deleteBlocked}</p>
          <ul className="mt-1 list-disc ps-5">
            {blockedBy.map((reason) => (
              <li key={reason}>{t.account.deleteBlockedBy[reason]}</li>
            ))}
          </ul>
        </Alert>
      ) : null}
      {error ? <Alert tone="danger">{error}</Alert> : null}
      {confirming ? (
        <div role="alertdialog" aria-labelledby="delete-title" className="space-y-3 rounded-xl bg-rose-50 p-4 ring-1 ring-rose-200">
          <p id="delete-title" className="font-semibold text-rose-900">
            {t.account.deleteConfirm}
          </p>
          <div className="flex flex-wrap gap-2">
            <Button variant="danger" onClick={remove} disabled={pending} data-testid="confirm-delete-account">
              {pending ? <Spinner /> : null}
              {t.account.deleteYes}
            </Button>
            <Button variant="ghost" onClick={() => setConfirming(false)} disabled={pending}>
              {t.account.deleteKeep}
            </Button>
          </div>
        </div>
      ) : (
        <Button variant="secondary" onClick={() => setConfirming(true)}>
          {t.account.deleteButton}
        </Button>
      )}
    </div>
  );
}
