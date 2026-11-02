import {
  useInfiniteQuery,
  useQuery,
  type InfiniteData,
  type QueryClient,
} from '@tanstack/react-query';

import { config } from '@/config';

import { api } from './endpoints';
import type { CampaignFilter, Page } from './types';

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

/** Polls progress while `live` is true; Reverb push replaces this with the E14 integration. */
export function useCampaignProgress(id: string, live: boolean) {
  return useQuery({
    queryKey: queryKeys.progress(id),
    queryFn: () => api.getCampaignProgress(id),
    enabled: live,
    refetchInterval: live ? config.progressPollMs : false,
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
