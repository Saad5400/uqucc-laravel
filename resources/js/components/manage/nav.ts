import { Activity, FileText, GraduationCap, LayoutDashboard, Settings, Users } from 'lucide-vue-next';
import type { FunctionalComponent } from 'vue';

export interface ManageNavItem {
    title: string;
    href: string;
    icon: FunctionalComponent;
    /** Permission required to see this item; omit for items visible to every panel user. */
    permission?: string;
}

export const manageNavItems: ManageNavItem[] = [
    { title: 'لوحة التحكم', href: '/manage', icon: LayoutDashboard },
    { title: 'الصفحات', href: '/manage/pages', icon: FileText },
    { title: 'المستخدمون', href: '/manage/users', icon: Users, permission: 'manage-users' },
    { title: 'الخصوصيون', href: '/manage/tutors', icon: GraduationCap, permission: 'manage-private-tutors' },
    { title: 'سجل النشاط', href: '/manage/activity', icon: Activity, permission: 'view-activity-logs' },
    { title: 'الإعدادات', href: '/manage/settings', icon: Settings },
];

/** Nav items the given user (by permission names) is allowed to see. */
export function visibleNavItems(permissions: string[]): ManageNavItem[] {
    return manageNavItems.filter((item) => !item.permission || permissions.includes(item.permission));
}

/** Matches the current Inertia URL against a nav item (exact for the dashboard, prefix for sections). */
export function isNavItemActive(item: ManageNavItem, currentUrl: string): boolean {
    const path = currentUrl.split('?')[0];

    if (item.href === '/manage') {
        return path === '/manage';
    }

    return path === item.href || path.startsWith(`${item.href}/`);
}
