import {
  useInfiniteQuery,
  useQuery,
  useQueryClient,
  type InfiniteData,
  type QueryClient,
} from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { AppState } from 'react-native';

import { config } from '@/config';
import { latestProgress } from '@/lib/campaign';
import { subscribeToProgress } from '@/live/progress';

import { api } from './endpoints';
import type { CampaignFilter, CampaignProgress, Page } from './types';

export const queryKeys = {
  categories: ['categories'] as const,
  campaigns: (filter: CampaignFilter, category: string | null) =>
    ['campaigns', filter, category] as const,
  campaign: (id: string) => ['campaign', id] as const,
  progress: (id: string) => ['campaign', id, 'progress'] as const,
  me: ['me'] as const,
  addresses: ['me', 'addresses'] as const,
  participations: ['me', 'participations'] as const,
  participation: (id: string) => ['me', 'participations', id] as const,
  orders: ['me', 'orders'] as const,
  order: (id: string) => ['me', 'orders', id] as const,
};

/** Everything under ['me'] belongs to the signed-in customer and is dropped at sign-out. */
export function clearPrivateData(queryClient: QueryClient): void {
  queryClient.removeQueries({ queryKey: ['me'] });
}

const nextCursor = <T>(page: Page<T>) => page.meta.next_cursor ?? undefined;

export function flattenPages<T>(data: InfiniteData<Page<T>> | undefined): T[] {
  return data?.pages.flatMap((page) => page.data) ?? [];
}

export function useCategories() {
  return useQuery({
    queryKey: queryKeys.categories,
    queryFn: () => api.listCategories(),
    staleTime: 10 * 60_000,
  });
}

export function useCampaigns(filter: CampaignFilter, category: string | null) {
  return useInfiniteQuery({
    queryKey: queryKeys.campaigns(filter, category),
    queryFn: ({ pageParam }) =>
      api.listCampaigns({ filter, category: category ?? undefined, cursor: pageParam }),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: nextCursor,
  });
}

export function useCampaign(id: string) {
  return useQuery({ queryKey: queryKeys.campaign(id), queryFn: () => api.getCampaign(id) });
}

/**
 * A campaign's progress while `live` is true. Reverb pushes changes (when configured); the
 * screen reads `/progress` on every (re)connect and when the app comes back to the
 * foreground, and polls every 30 seconds while pushes arrive, every 5 without them. Reads
 * and pushes keep the numbers with the latest `updated_at`.
 */
export function useCampaignProgress(id: string, live: boolean) {
  const queryClient = useQueryClient();
  const [pushing, setPushing] = useState(false);

  useEffect(() => {
    if (!live) return;
    const key = queryKeys.progress(id);
    const readNow = () => void queryClient.invalidateQueries({ queryKey: key, exact: true });
    const foreground = AppState.addEventListener('change', (state) => {
      if (state === 'active') readNow();
    });
    const stop = config.reverb
      ? subscribeToProgress(
          id,
          {
            onProgress: (pushed) =>
              queryClient.setQueryData<CampaignProgress>(key, (current) =>
                current ? latestProgress(current, pushed) : pushed,
              ),
            onLive: (now) => {
              setPushing(now);
              // Pushes only carry changes from here on: catch up first.
              if (now) readNow();
            },
          },
          config.reverb,
        )
      : undefined;
    return () => {
      foreground.remove();
      stop?.();
      setPushing(false);
    };
  }, [id, live, queryClient]);

  return useQuery({
    queryKey: queryKeys.progress(id),
    queryFn: async () => {
      const read = await api.getCampaignProgress(id);
      const shown = queryClient.getQueryData<CampaignProgress>(queryKeys.progress(id));
      // A push may have brought later numbers while this read was on its way.
      return shown ? latestProgress(shown, read) : read;
    },
    enabled: live,
    refetchInterval: live ? (pushing ? config.progressPushedPollMs : config.progressPollMs) : false,
  });
}

export function useMe(enabled: boolean) {
  return useQuery({ queryKey: queryKeys.me, queryFn: () => api.getMe(), enabled });
}

export function useAddresses(enabled = true) {
  return useQuery({
    queryKey: queryKeys.addresses,
    queryFn: () => api.listAddresses(),
    enabled,
  });
}

export function useParticipations(enabled = true) {
  return useInfiniteQuery({
    queryKey: queryKeys.participations,
    queryFn: ({ pageParam }) => api.listMyParticipations({ cursor: pageParam }),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: nextCursor,
    enabled,
  });
}

export function useParticipation(id: string) {
  return useQuery({
    queryKey: queryKeys.participation(id),
    queryFn: () => api.getMyParticipation(id),
  });
}

export function useOrders(enabled = true) {
  return useInfiniteQuery({
    queryKey: queryKeys.orders,
    queryFn: ({ pageParam }) => api.listMyOrders({ cursor: pageParam }),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: nextCursor,
    enabled,
  });
}

export function useOrder(id: string) {
  return useQuery({ queryKey: queryKeys.order(id), queryFn: () => api.getMyOrder(id) });
}
