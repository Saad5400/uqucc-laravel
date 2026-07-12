export type ChangeRequestStatus = 'pending' | 'approved' | 'rejected';

export interface ReviewPageRef {
    id: number;
    title: string;
    trashed: boolean;
}

/** A pending change as manage.reviews.index serializes it. */
export interface PendingReviewRow {
    id: number;
    page: ReviewPageRef | null;
    author_name: string | null;
    changed_fields: string[];
    created_at: string | null;
    updated_at: string | null;
}

export interface ReviewFieldChange {
    key: string;
    label: string;
    type: 'markdown' | 'bool' | 'text';
    old: string | boolean;
    new: string | boolean;
}

/** A change request as manage.reviews.show serializes it. */
export interface ReviewChangePayload {
    id: number;
    status: ChangeRequestStatus;
    author_name: string | null;
    reviewer_name: string | null;
    review_note: string | null;
    created_at: string | null;
    reviewed_at: string | null;
    page: {
        id: number;
        title: string;
        slug: string;
        trashed: boolean;
    } | null;
    changes: ReviewFieldChange[];
}

export const changeStatusLabels: Record<ChangeRequestStatus, string> = {
    pending: 'بانتظار المراجعة',
    approved: 'معتمد',
    rejected: 'مرفوض',
};
