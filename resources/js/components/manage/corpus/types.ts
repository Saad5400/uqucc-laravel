/** One AI corpus document row on the index. */
export interface CorpusDocumentRow {
    id: number;
    title: string;
    original_filename: string;
    is_pdf: boolean;
    size: number | null;
    status: CorpusDocumentStatus;
    error: string | null;
    index_status: CorpusIndexStatus | null;
    has_markdown: boolean;
    uploader_name: string | null;
    created_at: string | null;
}

/** The full document as the edit workspace receives it. */
export interface CorpusDocumentWorkspace extends Omit<CorpusDocumentRow, 'has_markdown'> {
    extracted_markdown: string | null;
}

export type CorpusDocumentStatus = 'pending' | 'extracting' | 'ready' | 'failed';

export type CorpusIndexStatus = 'pending' | 'processing' | 'ready' | 'failed';

export interface CorpusFilters {
    status: string | null;
    search: string | null;
}

export const extractionStatusLabels: Record<CorpusDocumentStatus, string> = {
    pending: 'بانتظار الاستخراج',
    extracting: 'جارٍ الاستخراج',
    ready: 'جاهز',
    failed: 'فشل',
};

export const indexStatusLabels: Record<CorpusIndexStatus, string> = {
    pending: 'بانتظار الفهرسة',
    processing: 'جارٍ الفهرسة',
    ready: 'مفهرس',
    failed: 'فشلت الفهرسة',
};

/** A document can be re-ingested once its text has been extracted. */
export function canReingest(document: Pick<CorpusDocumentRow, 'status' | 'has_markdown'>): boolean {
    return document.status === 'ready' && document.has_markdown;
}
