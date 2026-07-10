export interface UserRow {
    id: number;
    name: string;
    email: string;
    roles: string[];
    telegram_id: string | null;
    verified: boolean;
    username: string | null;
    url: string | null;
    avatar: string | null;
    pages_count: number;
    created_at: string | null;
}

export const roleLabels: Record<string, string> = {
    admin: 'مدير',
    editor: 'محرر',
};

/** Arabic-pluralized authored pages count, e.g. "٣ صفحات". */
export function formatPagesCount(count: number): string {
    if (count === 0) {
        return 'لا صفحات';
    }

    if (count === 1) {
        return 'صفحة واحدة';
    }

    if (count === 2) {
        return 'صفحتان';
    }

    if (count <= 10) {
        return `${count} صفحات`;
    }

    return `${count} صفحة`;
}
