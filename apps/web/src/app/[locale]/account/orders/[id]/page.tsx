import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { formatDate, formatMoney, interpolate, plural, ltr } from "@/i18n/format";
import { isLocale } from "@/i18n/config";
import { accountApi, loadOrRedirect, UUID } from "@/lib/account";
import { ApiError } from "@/lib/api/errors";
import { handlePageError } from "@/lib/api/server";
import { AddressLines, OrderStatusBadge } from "@/components/account-bits";
import { CampaignImage } from "@/components/campaign-bits";
import { Alert, Card, DescriptionList, Skeleton, cx } from "@/components/ui";

export async function generateMetadata({ params }: PageProps<"/[locale]/account/orders/[id]">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  return { title: getDictionary(locale).orders.title, robots: { index: false } };
}

async function OrderDetail({ params }: Pick<PageProps<"/[locale]/account/orders/[id]">, "params">) {
  const { t, locale } = await getI18n();
  const { id } = await params;
  if (!UUID.test(id)) handlePageError(new ApiError(404), locale, "");
  const here = `/${locale}/account/orders/${id}`;
  const api = await accountApi(locale, here);
  const { data: order } = await loadOrRedirect(
    api.GET("/me/orders/{order_id}", { params: { path: { order_id: id } } }),
    locale,
    here,
  );
  const shipment = order.shipment;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="text-2xl font-bold text-slate-900">
          {interpolate(t.orders.number, { number: ltr(order.order_number) })}
        </h1>
        <OrderStatusBadge status={order.status} />
      </div>
      <p className="-mt-4 text-sm text-slate-500">
        {interpolate(t.orders.placedOn, { date: formatDate(order.created_at, locale) })}
      </p>

      <div className="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
        <div className="space-y-6">
          <Card className="space-y-4">
            <h2 className="text-lg font-bold text-slate-900">{t.orders.summary}</h2>
            <div className="flex gap-4">
              <CampaignImage image={order.campaign.image} sizes="sm" className="size-20 shrink-0 rounded-xl" />
              <div className="min-w-0">
                <Link href={`/${locale}/campaigns/${order.campaign.id}`} className="font-semibold text-slate-900 hover:underline">
                  {order.campaign.title}
                </Link>
                <p className="mt-1 text-sm text-slate-600">
                  {order.product_variant.name} · {plural(t.common.units, order.quantity, locale)}
                </p>
              </div>
            </div>
            <DescriptionList
              items={[
                { label: t.orders.unitPrice, value: formatMoney(order.unit_price, locale) },
                { label: t.orders.goodsTotal, value: formatMoney(order.goods_total, locale) },
                {
                  label: t.orders.deliveryFee,
                  value: order.delivery_fee.amount_minor === 0 ? t.orders.free : formatMoney(order.delivery_fee, locale),
                },
                {
                  label: <span className="font-semibold text-slate-900">{t.orders.total}</span>,
                  value: <span className="text-lg font-bold">{formatMoney(order.total, locale)}</span>,
                },
              ]}
            />
            <p className="text-xs text-slate-500">{interpolate(t.orders.vatIncluded, { amount: formatMoney(order.vat, locale) })}</p>
            {order.payment.refunded_amount.amount_minor > 0 ? (
              <Alert tone="neutral">
                {interpolate(t.orders.refunded, { amount: formatMoney(order.payment.refunded_amount, locale) })}
              </Alert>
            ) : null}
          </Card>

          <Card className="space-y-4">
            <h2 className="text-lg font-bold text-slate-900">{t.orders.tracking}</h2>
            {shipment ? (
              <>
                <DescriptionList
                  items={[
                    { label: t.orders.courier, value: shipment.courier },
                    { label: t.orders.trackingNumber, value: <span dir="ltr">{shipment.tracking_number}</span> },
                  ]}
                />
                {shipment.tracking_url ? (
                  <a
                    href={shipment.tracking_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-block text-sm font-medium text-brand-700 hover:underline"
                  >
                    {t.orders.trackOnline}
                  </a>
                ) : null}
                <ol className="relative space-y-4 border-s-2 border-slate-200 ps-5" data-testid="shipment-events">
                  {[...shipment.events].reverse().map((event, index) => (
                    <li key={`${event.occurred_at}-${index}`} className="relative">
                      <span
                        aria-hidden
                        className={cx(
                          "absolute -start-[1.6rem] top-1 size-3 rounded-full ring-4 ring-white",
                          index === 0 ? "bg-brand-600" : "bg-slate-300",
                        )}
                      />
                      <p className={cx("text-sm", index === 0 ? "font-semibold text-slate-900" : "text-slate-700")}>
                        {t.orders.shipmentStatus[event.status]}
                      </p>
                      {event.description || event.location ? (
                        <p className="text-xs text-slate-500">
                          {[event.description, event.location].filter(Boolean).join(" · ")}
                        </p>
                      ) : null}
                      <p className="text-xs text-slate-500">{formatDate(event.occurred_at, locale, "dateTime")}</p>
                    </li>
                  ))}
                </ol>
              </>
            ) : (
              <p className="text-sm text-slate-600">{t.orders.noShipment}</p>
            )}
          </Card>
        </div>

        <div className="space-y-6">
          <Card className="space-y-3">
            <h2 className="text-lg font-bold text-slate-900">{t.orders.shipTo}</h2>
            <AddressLines address={order.shipping_address} />
          </Card>

          <Card className="space-y-3">
            <h2 className="text-lg font-bold text-slate-900">{t.orders.invoices}</h2>
            {order.invoices.length > 0 ? (
              <ul className="divide-y divide-slate-100">
                {order.invoices.map((invoice) => (
                  <li key={invoice.id} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                    <div>
                      <p className="font-medium text-slate-900">{t.orders.invoiceType[invoice.type]}</p>
                      <p className="text-xs text-slate-500">
                        <span dir="ltr">{invoice.invoice_number}</span> · {formatDate(invoice.issued_at, locale)} ·{" "}
                        {formatMoney(invoice.total, locale)}
                      </p>
                    </div>
                    {invoice.pdf_url ? (
                      <a
                        href={invoice.pdf_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="shrink-0 font-medium text-brand-700 hover:underline"
                      >
                        {t.orders.download}
                      </a>
                    ) : (
                      <span className="shrink-0 text-xs text-slate-500">{t.orders.notReady}</span>
                    )}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-sm text-slate-600">{t.orders.noInvoices}</p>
            )}
          </Card>

          {order.delivered_at || order.return_window_ends_at ? (
            <Card className="space-y-2 text-sm text-slate-700">
              {order.delivered_at ? (
                <p className="font-medium text-slate-900">
                  {interpolate(t.orders.deliveredOn, { date: formatDate(order.delivered_at, locale) })}
                </p>
              ) : null}
              {order.return_window_ends_at ? (
                <>
                  <p>{interpolate(t.orders.returnWindow, { date: formatDate(order.return_window_ends_at, locale) })}</p>
                  <p className="text-slate-500">
                    {t.orders.returnHow}{" "}
                    <Link href={`/${locale}/returns`} className="font-medium text-brand-700 hover:underline">
                      {t.footer.returns}
                    </Link>
                  </p>
                </>
              ) : null}
            </Card>
          ) : null}
        </div>
      </div>
    </div>
  );
}

export default function OrderDetailPage({ params }: PageProps<"/[locale]/account/orders/[id]">) {
  return (
    <Suspense fallback={<Skeleton className="h-96 rounded-2xl" />}>
      <OrderDetail params={params} />
    </Suspense>
  );
}
