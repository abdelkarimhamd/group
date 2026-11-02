import type { Metadata } from "next";
import { Suspense } from "react";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { isLocale } from "@/i18n/config";
import { accountApi, loadOrRedirect, UUID } from "@/lib/account";
import { ApiError } from "@/lib/api/errors";
import { handlePageError } from "@/lib/api/server";
import { JoinCheckout } from "@/components/join-checkout";
import { Container, PageHeader, Skeleton } from "@/components/ui";

export async function generateMetadata({ params }: PageProps<"/[locale]/campaigns/[id]/join">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  return { title: getDictionary(locale).pages.join, robots: { index: false } };
}

async function JoinData({
  params,
  searchParams,
}: Pick<PageProps<"/[locale]/campaigns/[id]/join">, "params" | "searchParams">) {
  const { locale } = await getI18n();
  const { id } = await params;
  const search = await searchParams;
  if (!UUID.test(id)) handlePageError(new ApiError(404), locale, "");

  const variant = typeof search.variant === "string" ? search.variant : "";
  const qty = Number.parseInt(typeof search.qty === "string" ? search.qty : "1", 10);
  const here = `/${locale}/campaigns/${id}/join?${new URLSearchParams({ variant, qty: String(qty || 1) })}`;

  const api = await accountApi(locale, here);
  const [campaign, me, addresses] = await Promise.all([
    loadOrRedirect(api.GET("/campaigns/{campaign_id}", { params: { path: { campaign_id: id } } }), locale, here),
    loadOrRedirect(api.GET("/me"), locale, here),
    loadOrRedirect(api.GET("/me/addresses"), locale, here),
  ]);

  const initialVariant = campaign.data.variants.find((item) => item.id === variant) ?? campaign.data.variants[0];
  return (
    <JoinCheckout
      campaign={campaign.data}
      user={me.data}
      addresses={addresses.data}
      initialVariantId={initialVariant?.id ?? ""}
      initialQuantity={Number.isFinite(qty) && qty > 0 ? Math.min(qty, 99) : 1}
    />
  );
}

export default async function JoinPage({ params, searchParams }: PageProps<"/[locale]/campaigns/[id]/join">) {
  const { t } = await getI18n();
  return (
    <Container className="py-8">
      <PageHeader title={t.join.title} />
      <Suspense
        fallback={
          <div className="grid gap-6 lg:grid-cols-[1fr_22rem]">
            <Skeleton className="h-96 rounded-2xl" />
            <Skeleton className="h-64 rounded-2xl" />
          </div>
        }
      >
        <JoinData params={params} searchParams={searchParams} />
      </Suspense>
    </Container>
  );
}
