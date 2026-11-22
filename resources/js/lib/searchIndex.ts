import { searchIndex } from '@/routes';
import type { SearchItem } from '@/types';

let pending: Promise<SearchItem[]> | null = null;

/**
 * The quick-search index, fetched once per page load and shared by every
 * caller. It used to ride in the props of every page and every Inertia visit
 * (~100 KB); now it is one browser-cacheable request, made only when a visitor
 * actually reaches for search. A failed fetch is forgotten, so the next focus
 * retries.
 */
export function loadSearchIndex(): Promise<SearchItem[]> {
    pending ??= fetch(searchIndex.url(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`search index: HTTP ${response.status}`);
            }

            return response.json() as Promise<SearchItem[]>;
        })
        .catch(() => {
            pending = null;

            return [];
        });

    return pending;
}
