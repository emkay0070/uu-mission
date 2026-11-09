import { resourcesApi } from '@/lib/api';
import type { Resource, ResourceDistributionRequest } from '@/lib/api';

const PAGE_SIZE = 100;
const MAX_PAGES = 20;

interface Page<T> {
  data: T[];
  last_page: number;
}

async function collectPages<T>(load: (page: number) => Promise<Page<T>>): Promise<T[]> {
  const items: T[] = [];
  let page = 1;
  let lastPage = 1;

  do {
    const response = await load(page);
    items.push(...(response.data ?? []));
    lastPage = Math.max(1, Number(response.last_page) || 1);
    page += 1;
  } while (page <= lastPage && page <= MAX_PAGES);

  return items;
}

/**
 * The API paginates drafts. Contribution management needs the whole set so a
 * draft never disappears from the list on a later page.
 */
export async function fetchAllMyDrafts(): Promise<Resource[]> {
  return collectPages((page) => resourcesApi.myDrafts({ per_page: PAGE_SIZE, page }));
}

/**
 * Load every distribution request the current user can see.
 *
 * The backend returns the user's own requests plus requests they are
 * authorised to review. Callers remain responsible for choosing the right
 * subset (own requests vs. review queue), but the API never has to be called
 * twice just to assemble one lifecycle view.
 */
export async function fetchAllDistributionRequests(
  params?: { status?: string }
): Promise<ResourceDistributionRequest[]> {
  return collectPages((page) =>
    resourcesApi.distributionRequests({ ...params, per_page: PAGE_SIZE, page })
  );
}
