import "server-only";
import { cache } from "react";
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

/**
 * One API call per page view: the page and its metadata both ask for the
 * campaign, and the fetches carry a signal (lib/api/server.ts), which keeps
 * Next from sharing them on its own.
 */
export const getCampaign = cache(async (locale: Locale, id: string) =>
  unwrap(createApi(locale).GET("/campaigns/{campaign_id}", { params: { path: { campaign_id: id } } })),
);
