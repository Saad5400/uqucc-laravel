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
    authoring_status: AuthoringStatus | null;
    authoring_error: string | null;
    authored_page: { id: number; title: string } | null;
    latest_proposal: { id: number; status: ProposalStatus; page_title: string | null } | null;
}

/** The full document as the edit workspace receives it. */
export interface CorpusDocumentWorkspace extends Omit<CorpusDocumentRow, 'has_markdown'> {
    extracted_markdown: string | null;
}

export type CorpusDocumentStatus = 'pending' | 'extracting' | 'ready' | 'failed';

export type CorpusIndexStatus = 'pending' | 'processing' | 'ready' | 'failed';

/** Document → page authoring lifecycle (null = never triggered). */
export type AuthoringStatus = 'queued' | 'running' | 'done' | 'failed';

export type ProposalStatus = 'pending' | 'applied' | 'rejected' | 'failed';

/** Gating info for the authoring actions (disabled-with-reason). */
export interface AuthoringGate {
    enabled: boolean;
    disabled_reason: string | null;
}

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

export const authoringStatusLabels: Record<AuthoringStatus, string> = {
    queued: 'التوليد بالانتظار',
    running: 'جارٍ التوليد',
    done: 'اكتمل التوليد',
    failed: 'فشل التوليد',
};

export const proposalStatusLabels: Record<ProposalStatus, string> = {
    pending: 'بانتظار المراجعة',
    applied: 'مطبَّق',
    rejected: 'مرفوض',
    failed: 'فشل التطبيق',
};

/** A document can be re-ingested once its text has been extracted. */
export function canReingest(document: Pick<CorpusDocumentRow, 'status' | 'has_markdown'>): boolean {
    return document.status === 'ready' && document.has_markdown;
}

/** Page authoring can run once extraction is done and no run is in flight. */
export function canAuthor(document: Pick<CorpusDocumentRow, 'status' | 'has_markdown' | 'authoring_status'>): boolean {
    return document.status === 'ready' && document.has_markdown && document.authoring_status !== 'queued' && document.authoring_status !== 'running';
}
