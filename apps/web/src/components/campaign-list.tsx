"use client";

import { useState, useTransition } from "react";
import { loadMoreCampaigns } from "@/actions/campaigns";
import { useI18n } from "@/i18n/client";
import type { CampaignFilter, CampaignSummary } from "@/lib/api/types";
import { CampaignCard } from "./campaign-card";
import { Alert, Button, Spinner } from "./ui";

export function CampaignGrid({ campaigns }: { campaigns: CampaignSummary[] }) {
  return (
    <ul className="grid gap-4 sm:grid-cols-2 sm:gap-6 lg:grid-cols-3">
      {campaigns.map((campaign) => (
        <li key={campaign.id} className="flex">
          <div className="flex w-full">
            <CampaignCard campaign={campaign} />
          </div>
        </li>
      ))}
    </ul>
  );
}

/** A campaign grid that appends the next page in place. */
export function CampaignList({
  initial,
  nextCursor: initialCursor,
  filter,
  category,
}: {
  initial: CampaignSummary[];
  nextCursor: string | null;
  filter: CampaignFilter;
  category?: string;
}) {
  const { t, locale } = useI18n();
  const [items, setItems] = useState(initial);
  const [cursor, setCursor] = useState(initialCursor);
  const [failed, setFailed] = useState(false);
  const [pending, startTransition] = useTransition();

  function loadMore() {
    if (!cursor) return;
    setFailed(false);
    startTransition(async () => {
      const result = await loadMoreCampaigns({ locale, filter, category, cursor });
      if (result.ok) {
        // A campaign can move between pages while the customer scrolls.
        setItems((current) => {
          const seen = new Set(current.map((item) => item.id));
          return [...current, ...result.data.filter((item) => !seen.has(item.id))];
        });
        setCursor(result.nextCursor);
      } else {
        setFailed(true);
      }
    });
  }

  return (
    <div>
      <CampaignGrid campaigns={items} />
      {failed ? (
        <Alert tone="danger" className="mt-6">
          {t.common.networkError}
        </Alert>
      ) : null}
      {cursor ? (
        <div className="mt-8 flex justify-center">
          <Button variant="secondary" onClick={loadMore} disabled={pending}>
            {pending ? <Spinner /> : null}
            {t.common.loadMore}
          </Button>
        </div>
      ) : null}
    </div>
  );
}
