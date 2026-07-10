export interface ActivityChanges {
    old: Record<string, unknown> | null;
    new: Record<string, unknown> | null;
}

export interface ActivityRow {
    id: number;
    log_name: string | null;
    description: string;
    event: string | null;
    subject_type: string | null;
    subject_id: number | null;
    subject_title: string | null;
    causer_name: string | null;
    created_at: string | null;
    created_at_human: string | null;
    changes: ActivityChanges | null;
}

export interface ActivityFilters {
    log_name: string | null;
    event: string | null;
    subject_type: string | null;
}

export interface ActivityFilterOptions {
    logNames: string[];
    events: string[];
    subjectTypes: string[];
}

/** Laravel's default length-aware paginator shape (the fields we use). */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export const eventLabels: Record<string, string> = {
    created: 'إنشاء',
    updated: 'تحديث',
    deleted: 'حذف',
    restored: 'استعادة',
};

export const subjectTypeLabels: Record<string, string> = {
    Page: 'صفحة',
    User: 'مستخدم',
    PrivateTutor: 'خصوصي',
    PrivateTutorCourse: 'مقرر',
};

export function subjectTypeLabel(basename: string): string {
    return subjectTypeLabels[basename] ?? basename;
}
