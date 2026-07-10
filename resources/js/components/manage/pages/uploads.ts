import { extractErrorMessage, xsrfToken } from '@/lib/http';

export interface UploadedPageFile {
    url: string;
    path: string;
}

/**
 * Upload a file to the pages upload endpoint (plain fetch, not an Inertia
 * visit, so the JSON response with the stored path/URL comes straight back).
 *
 * `editor` files land at the public disk root (where the previous admin
 * panel stored rich-editor image attachments); `quick_response` files land
 * in `quick-responses/` with their original filename preserved.
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
        throw new Error(await extractErrorMessage(response, 'تعذر رفع الملف.'));
    }

    return response.json();
}
