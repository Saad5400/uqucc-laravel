/**
 * Shared helpers for the manage panel's plain-fetch endpoints (uploads,
 * copilot) — the ones that return JSON straight back instead of an Inertia
 * visit.
 */

/** The session's XSRF token, as Laravel expects it in the `X-XSRF-TOKEN` header. */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
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
            'X-XSRF-TOKEN': xsrfToken(),
            Accept: 'application/json',
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        throw new Error(await extractErrorMessage(response, errorFallback));
    }

    return response.json() as Promise<T>;
}
