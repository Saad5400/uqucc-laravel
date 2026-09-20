export type InviteView = 'overview' | 'joins' | 'links' | 'requests';

export interface InviteFilters {
    period: string;
    chat: string | null;
    q: string;
    view: InviteView;
    link_status: string;
    join_source: string;
}

export interface InviteStats {
    joins: number;
    attributedJoins: number;
    attributionRate: number | null;
    links: number;
    usedLinks: number;
    unusedLinks: number;
    inviters: number;
    usageRate: number | null;
}

export interface LeaderboardRow {
    telegram_user_id: string;
    username: string | null;
    name: string | null;
    joins: number;
    links: number;
    last_join_at: string | null;
}

export interface JoinRow {
    id: number;
    joiner: string;
    joiner_username: string | null;
    joiner_telegram_user_id: string;
    inviter: string | null;
    inviter_username: string | null;
    inviter_telegram_user_id: string | null;
    chat_title: string | null;
    invite_link: string | null;
    link_name: string | null;
    source: string;
    joined_at: string | null;
}

export interface InviteLinkRow {
    id: number;
    invite_link: string;
    link_name: string | null;
    creator: string | null;
    creator_username: string | null;
    creator_telegram_user_id: string;
    chat_title: string | null;
    chat_id: string;
    joins_count: number;
    member_limit: number | null;
    expires_at: string | null;
    created_at: string | null;
    status: 'available' | 'used' | 'expired';
}

export interface RequestRow {
    telegram_user_id: string;
    username: string | null;
    name: string | null;
    requests: number;
    before_tracking: number;
    last_used_at: string | null;
}

export function displayTelegramIdentity(row: { name?: string | null; username?: string | null; telegram_user_id: string }): string {
    return row.name || (row.username ? `@${row.username}` : row.telegram_user_id);
}

export const sourceLabels: Record<string, string> = {
    invite_link: 'عبر رابط دعوة',
    added_by_admin: 'أضافه مشرف مباشرة',
    self: 'انضم دون رابط مسجّل',
};

export const linkStatusLabels: Record<InviteLinkRow['status'], string> = {
    available: 'متاح',
    used: 'مُستخدم',
    expired: 'منتهي',
};
