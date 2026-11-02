"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { useMemo, useRef, useState, useTransition } from "react";
import { acceptPrivacy } from "@/actions/account";
import { joinCampaign } from "@/actions/participations";
import { useI18n } from "@/i18n/client";
import { formatMoney, formatNumber, interpolate, plural, type MoneyLike, ltr } from "@/i18n/format";
import { estimateHold, maxJoinQuantity } from "@/lib/pricing";
import type { ActionFailure } from "@/lib/api/errors";
import type { Address, CampaignDetail, Participation, User } from "@/lib/api/types";
import { AddressLines } from "./account-bits";
import { AddressForm } from "./address-form";
import { CampaignImage, QuantityStepper, VariantPicker } from "./campaign-bits";
import { LiveCampaignProvider, useProgress } from "./campaign-live";
import { PaymentStep } from "./payment-step";
import { Alert, Button, ButtonLink, Card, cx, DescriptionList, Spinner } from "./ui";

type Props = {
  campaign: CampaignDetail;
  user: User;
  addresses: Address[];
  initialVariantId: string;
  initialQuantity: number;
};

export function JoinCheckout(props: Props) {
  return (
    <LiveCampaignProvider campaignId={props.campaign.id} initial={props.campaign.progress}>
      <Checkout {...props} />
    </LiveCampaignProvider>
  );
}

function newRequestId(): string {
  if (typeof crypto.randomUUID === "function") return crypto.randomUUID();
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6]! & 0x0f) | 0x40;
  bytes[8] = (bytes[8]! & 0x3f) | 0x80;
  const hex = [...bytes].map((byte) => byte.toString(16).padStart(2, "0")).join("");
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

function Checkout({ campaign, user, addresses: serverAddresses, initialVariantId, initialQuantity }: Props) {
  const { t, locale } = useI18n();
  const router = useRouter();
  const progress = useProgress();

  const [variantId, setVariantId] = useState(initialVariantId);
  const [rawQuantity, setQuantity] = useState(initialQuantity);
  const max = maxJoinQuantity(progress.remaining_qty, campaign.per_customer_max_qty);
  const quantity = Math.min(Math.max(1, rawQuantity), Math.max(1, max));

  // Addresses saved here are shown at once, before the page data refreshes.
  const [added, setAdded] = useState<Address[]>([]);
  const addresses = useMemo(() => {
    const byId = new Map(serverAddresses.map((address) => [address.id, address]));
    for (const address of added) if (!byId.has(address.id)) byId.set(address.id, address);
    return [...byId.values()];
  }, [serverAddresses, added]);
  const [addressId, setAddressId] = useState(
    () => (serverAddresses.find((address) => address.is_default) ?? serverAddresses[0])?.id ?? "",
  );
  const [addingAddress, setAddingAddress] = useState(serverAddresses.length === 0);

  const consentCurrent = user.privacy_consent?.policy_version === user.current_privacy_policy_version;
  const [consented, setConsented] = useState(consentCurrent);
  const [privacyChecked, setPrivacyChecked] = useState(false);

  // After a 409 price_changed the new price is shown until the live poll
  // catches up with it.
  const [conflict, setConflict] = useState<{ price: MoneyLike; livePriceThen: number } | null>(null);
  const unitPrice =
    conflict && conflict.livePriceThen === progress.current_unit_price.amount_minor
      ? conflict.price
      : progress.current_unit_price;
  const hold = estimateHold(unitPrice, quantity);
  const lowest = estimateHold(campaign.lowest_unit_price, quantity);

  const [error, setError] = useState<string | null>(null);
  const [joined, setJoined] = useState<Participation | null>(null);
  const [pending, startTransition] = useTransition();
  // Kept across retries of one attempt so a lost response never joins twice;
  // dropped once the API has answered with a refusal.
  const requestId = useRef<string | null>(null);

  const variant = campaign.variants.find((item) => item.id === variantId) ?? campaign.variants[0];
  const joinable = progress.status === "active" && max >= 1;

  function failureMessage(failure: ActionFailure): string {
    switch (failure.code) {
      case "price_changed":
        if (failure.currentUnitPrice) {
          setConflict({ price: failure.currentUnitPrice, livePriceThen: progress.current_unit_price.amount_minor });
          return interpolate(t.join.priceChanged, { price: formatMoney(failure.currentUnitPrice, locale) });
        }
        return t.common.genericError;
      case "sold_out":
        if (failure.remainingQty && failure.remainingQty > 0) {
          setQuantity(failure.remainingQty);
          return interpolate(t.join.soldOut, { units: plural(t.common.units, failure.remainingQty, locale) });
        }
        return t.join.soldOutNone;
      case "campaign_not_joinable":
        return t.join.notJoinable;
      case "privacy_consent_required":
        setConsented(false);
        setPrivacyChecked(false);
        return t.join.privacyUpdated;
      case "per_customer_limit_exceeded":
        return t.join.limitReached;
      case "variant_not_in_campaign":
        return t.join.variantMissing;
      case "address_not_found":
        return t.join.addressMissing;
    }
    if (failure.status === 429) {
      return interpolate(t.auth.errors.rateLimited, { seconds: formatNumber(failure.retryAfter ?? 60, locale) });
    }
    return failure.status === 0 ? t.common.networkError : t.common.genericError;
  }

  function confirm() {
    setError(null);
    if (!addressId || addingAddress) {
      setError(t.join.noAddresses);
      return;
    }
    if (!consented && !privacyChecked) {
      setError(t.join.privacyRequired);
      return;
    }
    startTransition(async () => {
      if (!consented) {
        const consent = await acceptPrivacy({ locale, policyVersion: user.current_privacy_policy_version });
        if (!consent.ok) {
          setError(consent.status === 0 ? t.common.networkError : t.common.genericError);
          return;
        }
        setConsented(true);
      }
      requestId.current ??= newRequestId();
      const result = await joinCampaign({
        locale,
        campaignId: campaign.id,
        variantId: variant?.id ?? variantId,
        quantity,
        shownUnitPriceHalalas: unitPrice.amount_minor,
        addressId,
        clientRequestId: requestId.current,
      });
      if (result.ok) {
        setJoined(result.participation);
        window.scrollTo({ top: 0, behavior: "smooth" });
        return;
      }
      if (result.status === 401) {
        router.replace(`/${locale}/sign-in?expired=1&next=${encodeURIComponent(window.location.pathname + window.location.search)}`);
        return;
      }
      if (result.status !== 0) requestId.current = null;
      setError(failureMessage(result));
    });
  }

  const summary = (
    <Card className="space-y-4">
      <div className="flex gap-4">
        <CampaignImage image={campaign.image} sizes="sm" className="size-20 shrink-0 rounded-xl" />
        <div className="min-w-0">
          <Link href={`/${locale}/campaigns/${campaign.id}`} className="font-semibold text-slate-900 hover:underline">
            {campaign.title}
          </Link>
          <p className="mt-1 text-xs text-slate-500">
            {interpolate(t.campaign.code, { code: ltr(campaign.code) })}
          </p>
        </div>
      </div>
      <DescriptionList
        items={[
          campaign.variants.length > 1 && variant ? { label: t.join.option, value: variant.name } : null,
          { label: t.join.quantity, value: plural(t.common.units, joined?.quantity ?? quantity, locale) },
          {
            label: t.join.unitPriceNow,
            value: formatMoney(joined?.hold_unit_price ?? unitPrice, locale),
          },
          {
            label: t.join.holdAmount,
            value: (
              <span className="text-lg font-bold" data-testid="checkout-hold">
                {formatMoney(joined?.hold_amount ?? hold, locale)}
              </span>
            ),
          },
          !joined && lowest.amount_minor < hold.amount_minor
            ? { label: t.join.lowestCharge, value: <span className="text-deal-700">{formatMoney(lowest, locale)}</span> }
            : null,
        ]}
      />
      <p className="text-xs text-slate-500">{t.campaign.cancellationRule[campaign.cancellation_rule]}</p>
    </Card>
  );

  if (joined) {
    const payment = joined.payment;
    return (
      <div className="grid gap-6 lg:grid-cols-[1fr_22rem]">
        <div className="space-y-4">
          {joined.status === "pending_payment" && payment?.status === "pending_authorization" ? (
            <PaymentStep participation={joined} payment={payment} />
          ) : (
            <Card className="space-y-4">
              <Alert tone="success">{t.joins.statusHelp[joined.status]}</Alert>
              <ButtonLink href={`/${locale}/account/joins/${joined.id}`}>{t.payment.viewJoin}</ButtonLink>
            </Card>
          )}
        </div>
        <div>{summary}</div>
      </div>
    );
  }

  if (!joinable) {
    return (
      <Card className="space-y-4">
        <Alert tone="warning">{progress.status === "active" ? t.campaign.notJoinable.soldOut : t.join.notJoinable}</Alert>
        <ButtonLink href={`/${locale}/campaigns/${campaign.id}`} variant="secondary">
          {t.payment.backToCampaign}
        </ButtonLink>
      </Card>
    );
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_22rem]">
      <div className="lg:col-start-2 lg:row-start-1">
        <div className="lg:sticky lg:top-24">{summary}</div>
      </div>
      <div className="space-y-6 lg:col-start-1 lg:row-start-1">
        <Card className="space-y-5">
          <h2 className="text-lg font-bold text-slate-900">{t.join.stepDetails}</h2>
          {campaign.variants.length > 1 ? (
            <VariantPicker variants={campaign.variants} value={variantId} onChange={setVariantId} />
          ) : null}
          <QuantityStepper
            value={quantity}
            max={max}
            onChange={setQuantity}
            disabled={pending}
            note={
              campaign.per_customer_max_qty
                ? interpolate(t.campaign.maxPerCustomer, {
                    units: plural(t.common.units, campaign.per_customer_max_qty, locale),
                  })
                : null
            }
          />
        </Card>

        <Card className="space-y-4">
          <h2 className="text-lg font-bold text-slate-900">{t.join.stepAddress}</h2>
          {addresses.length > 0 ? (
            <fieldset className="space-y-2">
              <legend className="sr-only">{t.join.stepAddress}</legend>
              {addresses.map((address) => (
                <label
                  key={address.id}
                  className={cx(
                    "flex cursor-pointer gap-3 rounded-xl p-4 ring-1 transition has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-brand-600",
                    address.id === addressId && !addingAddress ? "bg-brand-50 ring-brand-500" : "bg-white ring-slate-200",
                  )}
                >
                  <input
                    type="radio"
                    name="address"
                    value={address.id}
                    checked={address.id === addressId && !addingAddress}
                    onChange={() => {
                      setAddressId(address.id);
                      setAddingAddress(false);
                    }}
                    className="mt-1 size-4 text-brand-700 focus:ring-brand-600"
                  />
                  <AddressLines address={address} />
                </label>
              ))}
            </fieldset>
          ) : (
            <p className="text-sm text-slate-600">{t.join.noAddresses}</p>
          )}
          {addingAddress ? (
            <div className="rounded-xl bg-slate-50 p-4 ring-1 ring-slate-200">
              <h3 className="mb-4 font-semibold text-slate-900">{t.addresses.addTitle}</h3>
              <AddressForm
                defaults={{ name: user.name, phone: user.phone }}
                makeDefaultInitially={addresses.length === 0}
                onSaved={(address) => {
                  setAdded((current) => [...current, address]);
                  setAddressId(address.id);
                  setAddingAddress(false);
                }}
                onCancel={addresses.length > 0 ? () => setAddingAddress(false) : undefined}
              />
            </div>
          ) : (
            <Button variant="ghost" onClick={() => setAddingAddress(true)} className="px-0">
              + {t.join.addAddress}
            </Button>
          )}
        </Card>

        <Card className="space-y-4">
          <h2 className="text-lg font-bold text-slate-900">{t.join.rulesTitle}</h2>
          <ul className="list-disc space-y-2 ps-5 text-sm text-slate-700">
            {t.join.rules.map((rule) => (
              <li key={rule}>{rule}</li>
            ))}
          </ul>
          {!consented ? (
            <label className="flex items-start gap-3 rounded-xl bg-slate-50 p-4 text-sm text-slate-800 ring-1 ring-slate-200">
              <input
                type="checkbox"
                checked={privacyChecked}
                onChange={(event) => setPrivacyChecked(event.target.checked)}
                className="mt-0.5 size-5 rounded border-slate-300 text-brand-700 focus:ring-brand-600"
                data-testid="privacy-consent"
              />
              <span>
                {user.privacy_consent ? <span className="mb-1 block font-medium">{t.join.privacyUpdated}</span> : null}
                {t.join.privacyAgree.split("{link}")[0]}
                <Link href={`/${locale}/privacy`} target="_blank" className="font-medium text-brand-700 underline">
                  {t.join.privacyLink}
                </Link>
                {t.join.privacyAgree.split("{link}")[1]}
              </span>
            </label>
          ) : null}
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <Button onClick={confirm} disabled={pending} className="w-full" data-testid="confirm-join">
            {pending ? <Spinner /> : null}
            {pending ? t.join.confirming : t.join.confirm}
          </Button>
        </Card>
      </div>
    </div>
  );
}
