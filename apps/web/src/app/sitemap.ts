import type { MetadataRoute } from "next";
import { connection } from "next/server";
import { locales } from "@/i18n/config";
import { listCampaigns } from "@/lib/campaigns";
import { orNull } from "@/lib/api/server";
import { siteUrl } from "@/lib/site";

const STATIC_PATHS = ["", "/campaigns", "/how-it-works", "/privacy", "/terms", "/returns"];

function entry(path: string): MetadataRoute.Sitemap[number] {
  const base = siteUrl();
  return {
    url: `${base}/ar${path}`,
    alternates: { languages: Object.fromEntries(locales.map((l) => [`${l}-SA`, `${base}/${l}${path}`])) },
  };
}

// Built per request so open campaigns are always current.
export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  await connection();
  const [open, upcoming] = await Promise.all([
    orNull(listCampaigns("ar", { filter: "open", perPage: 100 })),
    orNull(listCampaigns("ar", { filter: "upcoming", perPage: 100 })),
  ]);
  const campaigns = [...(open?.data ?? []), ...(upcoming?.data ?? [])];
  return [...STATIC_PATHS.map(entry), ...campaigns.map((campaign) => entry(`/campaigns/${campaign.id}`))];
}
