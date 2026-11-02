import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { formatDate, formatMoney, interpolate, plural } from "@/i18n/format";
import { isLocale } from "@/i18n/config";
import { accountApi, loadOrRedirect, UUID } from "@/lib/account";
import { ApiError } from "@/lib/api/errors";
import { handlePageError } from "@/lib/api/server";
import { ParticipationStatusBadge, PaymentStatusBadge } from "@/components/account-bits";
import { CampaignImage } from "@/components/campaign-bits";
import { TimeUntil } from "@/components/countdown";
import { JoinActions } from "@/components/join-actions";
import { Alert, Card, DescriptionList, Skeleton } from "@/components/ui";

export async function generateMetadata({ params }: PageProps<"/[locale]/account/joins/[id]">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  return { title: getDictionary(locale).joins.details, robots: { index: false } };
}

async function JoinDetail({ params }: Pick<PageProps<"/[locale]/account/joins/[id]">, "params">) {
  const { t, locale } = await getI18n();
  const { id } = await params;
  if (!UUID.test(id)) handlePageError(new ApiError(404), locale, "");
  const here = `/${locale}/account/joins/${id}`;
  const api = await accountApi(locale, here);
  const { data: join } = await loadOrRedirect(
    api.GET("/me/participations/{participation_id}", { params: { path: { participation_id: id } } }),
    locale,
    here,
  );
  const payment = join.payment;
  const released =
    join.capture_amount && join.capture_amount.amount_minor < join.hold_amount.amount_minor
      ? { amount_minor: join.hold_amount.amount_minor - join.capture_amount.amount_minor, currency: join.hold_amount.currency }
      : null;

  return (
    <div className="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
      <div className="space-y-6">
        <Card className="space-y-5">
          <div className="flex gap-4">
            <CampaignImage image={join.campaign.image} sizes="sm" className="size-20 shrink-0 rounded-xl" />
            <div className="min-w-0 space-y-2">
              <Link href={`/${locale}/campaigns/${join.campaign.id}`} className="font-semibold text-slate-900 hover:underline">
                {join.campaign.title}
              </Link>
              <div>
                <ParticipationStatusBadge status={join.status} />
              </div>
            </div>
          </div>
          <p className="text-sm text-slate-700" data-testid="join-status-help">
            {t.joins.statusHelp[join.status]}
          </p>
          {join.status === "pending_payment" ? (
            <Alert tone="warning">
              <TimeUntil iso={join.reservation_expires_at} text={t.joins.reservationEnds} />
            </Alert>
          ) : null}
          <DescriptionList
            items={[
              { label: t.joins.option, value: join.product_variant.name },
              { label: t.joins.quantity, value: plural(t.common.units, join.quantity, locale) },
              { label: t.joins.holdUnitPrice, value: formatMoney(join.hold_unit_price, locale) },
              { label: t.joins.holdAmount, value: formatMoney(join.hold_amount, locale) },
              join.final_unit_price ? { label: t.joins.finalUnitPrice, value: formatMoney(join.final_unit_price, locale) } : null,
              join.capture_amount ? { label: t.joins.chargedAmount, value: formatMoney(join.capture_amount, locale) } : null,
            ]}
          />
          {join.capture_amount ? (
            released ? (
              <p className="text-sm text-deal-700">{interpolate(t.joins.releasedRest, { amount: formatMoney(released, locale) })}</p>
            ) : null
          ) : join.status === "authorized" || join.status === "pending_payment" ? (
            <p className="text-sm text-slate-500">{t.joins.maxNote}</p>
          ) : null}
          <p className="text-xs text-slate-500">
            {interpolate(t.joins.joinedOn, { date: formatDate(join.created_at, locale, "dateTime") })}
          </p>
        </Card>
        <JoinActions participation={join} />
      </div>

      {payment ? (
        <Card className="h-fit space-y-3">
          <h2 className="text-lg font-bold text-slate-900">{t.joins.payment}</h2>
          <DescriptionList
            items={[
              { label: t.joins.paymentStatus, value: <PaymentStatusBadge status={payment.status} /> },
              payment.scheme && payment.card_last4
                ? {
                    label: t.joins.card,
                    value: interpolate(t.joins.cardValue, { scheme: t.joins.schemes[payment.scheme], last4: payment.card_last4 }),
                  }
                : null,
              payment.status === "authorized"
                ? { label: t.joins.holdExpires, value: formatDate(payment.expires_at, locale) }
                : null,
            ]}
          />
          {payment.rejection_reason ? <Alert tone="danger">{t.payment.reasons[payment.rejection_reason]}</Alert> : null}
        </Card>
      ) : null}
    </div>
  );
}

export default async function JoinDetailPage({ params }: PageProps<"/[locale]/account/joins/[id]">) {
  const { t } = await getI18n();
  return (
    <>
      <h1 className="mb-6 text-2xl font-bold text-slate-900">{t.joins.details}</h1>
      <Suspense fallback={<Skeleton className="h-96 rounded-2xl" />}>
        <JoinDetail params={params} />
      </Suspense>
    </>
  );
}
