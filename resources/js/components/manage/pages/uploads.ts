export interface UploadedPageFile {
    url: string;
    path: string;
}

/**
 * Upload a file to the pages upload endpoint (plain fetch, not an Inertia
 * visit, so the JSON response with the stored path/URL comes straight back).
 *
 * `editor` files land where Filament's RichEditor stored image attachments
 * (public disk root); `quick_response` files land in `quick-responses/`
 * with their original filename preserved.
 */
export async function uploadPageFile(file: File, type: 'editor' | 'quick_response'): Promise<UploadedPageFile> {
    const body = new FormData();
    body.append('type', type);
    body.append('file', file);

    const response = await fetch('/manage/pages/uploads', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'X-XSRF-TOKEN': xsrfToken(),
            Accept: 'application/json',
        },
        body,
    });

    if (!response.ok) {
        throw new Error(await extractErrorMessage(response));
    }

    return response.json();
}

async function extractErrorMessage(response: Response): Promise<string> {
    const fallback = 'تعذر رفع الملف.';

    try {
        const data = (await response.json()) as { message?: string; errors?: Record<string, string[]> };

        return Object.values(data.errors ?? {})[0]?.[0] ?? data.message ?? fallback;
    } catch {
        return fallback;
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}
