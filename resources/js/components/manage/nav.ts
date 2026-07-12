import { Activity, Bot, FileText, FileUp, GraduationCap, LayoutDashboard, ListChecks, Settings, Sparkles, Users } from 'lucide-vue-next';
import type { FunctionalComponent } from 'vue';

export interface ManageNavItem {
    title: string;
    href: string;
    icon: FunctionalComponent;
    /** Permission required to see this item; omit for items visible to every panel user. */
    permission?: string;
    /** Whether the item is only visible to users who can review pending changes. */
    reviewOnly?: boolean;
}

export const manageNavItems: ManageNavItem[] = [
    { title: 'لوحة التحكم', href: '/manage', icon: LayoutDashboard },
    { title: 'الصفحات', href: '/manage/pages', icon: FileText },
    { title: 'المراجعات', href: '/manage/reviews', icon: ListChecks, reviewOnly: true },
    { title: 'المستخدمون', href: '/manage/users', icon: Users, permission: 'manage-users' },
    { title: 'الخصوصيون', href: '/manage/tutors', icon: GraduationCap, permission: 'manage-private-tutors' },
    { title: 'المساعد الإداري', href: '/manage/assistant', icon: Sparkles },
    { title: 'مستندات الذكاء الاصطناعي', href: '/manage/corpus', icon: FileUp },
    { title: 'ذكاء بوت التليجرام', href: '/manage/telegram-chats', icon: Bot },
    { title: 'سجل النشاط', href: '/manage/activity', icon: Activity, permission: 'view-activity-logs' },
    { title: 'الإعدادات', href: '/manage/settings', icon: Settings },
];

/** Nav items the given user is allowed to see, by held permissions and review access. */
export function visibleNavItems(permissions: string[], canReviewChanges = false): ManageNavItem[] {
    return manageNavItems.filter((item) => {
        if (item.reviewOnly && !canReviewChanges) {
            return false;
        }

        return !item.permission || permissions.includes(item.permission);
    });
}

/** Matches the current Inertia URL against a nav item (exact for the dashboard, prefix for sections). */
export function isNavItemActive(item: ManageNavItem, currentUrl: string): boolean {
    const path = currentUrl.split('?')[0];

    if (item.href === '/manage') {
        return path === '/manage';
    }

    return path === item.href || path.startsWith(`${item.href}/`);
}
