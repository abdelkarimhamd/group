import { $api } from './client';

/** Small reference lists used by selects and for showing names next to IDs. */

export function useCategories() {
  return $api.useQuery('get', '/admin/categories', undefined, { staleTime: 5 * 60_000 });
}

/** The first 100 suppliers. Enough for the pilot; switch to a search box when it is not. */
export function useSupplierOptions(enabled = true) {
  return $api.useQuery(
    'get',
    '/admin/suppliers',
    { params: { query: { per_page: 100 } } },
    { staleTime: 60_000, enabled },
  );
}

export function useProductOptions(enabled = true) {
  return $api.useQuery(
    'get',
    '/admin/products',
    { params: { query: { per_page: 100 } } },
    { staleTime: 60_000, enabled },
  );
}
