/**
 * Shared helpers for the plain-fetch endpoints (the public tools and
 * assistant, the manage panel's uploads and copilot) — the ones that return
 * JSON straight back instead of an Inertia visit.
 */
import { csrfCookie } from '@/routes';

/** The session's XSRF token, as Laravel expects it in the `X-XSRF-TOKEN` header. */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * The XSRF token, starting the visitor's session first when there is none yet:
 * a public page answered from the full-page cache before the session layer
 * (ServeCachedPage) sets no cookie, so the first POST from it would 419.
 */
export async function ensureXsrfToken(): Promise<string> {
    const token = xsrfToken();

    if (token !== '') {
        return token;
    }

    await fetch(csrfCookie.url(), { credentials: 'same-origin', headers: { Accept: 'application/json' } });

    return xsrfToken();
}

/** First error (or `message`) from a failed Laravel JSON response, falling back to a caller-provided default. */
export async function extractErrorMessage(response: Response, fallback: string): Promise<string> {
    try {
        const data = (await response.json()) as { message?: string; errors?: Record<string, string[]> };

        return Object.values(data.errors ?? {})[0]?.[0] ?? data.message ?? fallback;
    } catch {
        return fallback;
    }
}

/** POST a JSON body and return the parsed JSON response; throws the extracted Arabic message on failure. */
export async function postJson<T>(url: string, body: Record<string, unknown>, errorFallback: string): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': await ensureXsrfToken(),
            Accept: 'application/json',
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(await extractErrorMessage(response, errorFallback));
    }

    return response.json() as Promise<T>;
}
