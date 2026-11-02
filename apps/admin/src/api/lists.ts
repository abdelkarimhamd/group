import { useInfiniteQuery, type InfiniteData } from '@tanstack/react-query';
import { useMemo } from 'react';
import type { CursorMeta } from './types';

export type Page<T> = { data: T[]; meta: CursorMeta };

/**
 * Cursor pagination for list endpoints. The key starts with ['get', path] like
 * the openapi-react-query hooks, so invalidating ['get', path] refreshes both.
 */
export function useCursorList<T>(
  key: readonly [string, string, ...unknown[]],
  fetchPage: (cursor: string | undefined, signal: AbortSignal) => Promise<Page<T>>,
  options: { enabled?: boolean } = {},
) {
  const query = useInfiniteQuery<
    Page<T>,
    Error,
    InfiniteData<Page<T>>,
    readonly unknown[],
    string | undefined
  >({
    queryKey: key,
    queryFn: ({ pageParam, signal }) => fetchPage(pageParam, signal),
    initialPageParam: undefined,
    getNextPageParam: (last) => last.meta.next_cursor ?? undefined,
    enabled: options.enabled ?? true,
  });
  const items = useMemo(() => query.data?.pages.flatMap((p) => p.data) ?? [], [query.data]);
  return { ...query, items };
}

export type CursorList<T> = ReturnType<typeof useCursorList<T>>;

/** Drops empty filter values so they never reach the query string. */
export function cleanQuery<Q extends Record<string, unknown>>(query: Q): Q {
  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null || v === '') continue;
    out[k] = v;
  }
  return out as Q;
}

/** Every call either returns data or throws ApiError (see client.ts), so data is present. */
export function unwrap<T>(result: { data?: T }): T {
  return result.data as T;
}
