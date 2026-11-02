import "server-only";
import type { Locale } from "@/i18n/config";
import { createApi, unwrap } from "@/lib/api/server";
import type { CampaignFilter } from "@/lib/api/types";

export const CAMPAIGN_FILTERS: CampaignFilter[] = ["open", "upcoming", "ended"];

export function parseFilter(value: unknown): CampaignFilter {
  return CAMPAIGN_FILTERS.includes(value as CampaignFilter) ? (value as CampaignFilter) : "open";
}

export async function listCampaigns(
  locale: Locale,
  query: { filter: CampaignFilter; category?: string; cursor?: string; perPage?: number },
) {
  return unwrap(
    createApi(locale).GET("/campaigns", {
      params: {
        query: {
          filter: query.filter,
          category: query.category || undefined,
          cursor: query.cursor || undefined,
          per_page: query.perPage ?? 12,
        },
      },
    }),
  );
}

export async function listCategories(locale: Locale) {
  return unwrap(createApi(locale).GET("/categories"));
}

export async function getCampaign(locale: Locale, id: string) {
  return unwrap(createApi(locale).GET("/campaigns/{campaign_id}", { params: { path: { campaign_id: id } } }));
}
