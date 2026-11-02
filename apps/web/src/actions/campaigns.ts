"use server";

import { isLocale } from "@/i18n/config";
import { listCampaigns, parseFilter } from "@/lib/campaigns";
import { toActionFailure } from "@/lib/api/errors";

/** Next page of a campaign list, for the "Show more" button. */
export async function loadMoreCampaigns(input: {
  locale: string;
  filter: string;
  category?: string;
  cursor: string;
}) {
  if (!isLocale(input.locale)) return { ok: false as const, status: 400 };
  try {
    const page = await listCampaigns(input.locale, {
      filter: parseFilter(input.filter),
      category: input.category,
      cursor: input.cursor,
    });
    return { ok: true as const, data: page.data, nextCursor: page.meta.next_cursor };
  } catch (error) {
    return toActionFailure(error);
  }
}
