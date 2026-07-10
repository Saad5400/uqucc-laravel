import { Activity, FileText, GraduationCap, LayoutDashboard, Settings, Users } from 'lucide-vue-next';
import type { FunctionalComponent } from 'vue';

export interface ManageNavItem {
    title: string;
    href: string;
    icon: FunctionalComponent;
}

export const manageNavItems: ManageNavItem[] = [
    { title: 'لوحة التحكم', href: '/manage', icon: LayoutDashboard },
    { title: 'الصفحات', href: '/manage/pages', icon: FileText },
    { title: 'المستخدمون', href: '/manage/users', icon: Users },
    { title: 'الخصوصيون', href: '/manage/tutors', icon: GraduationCap },
    { title: 'سجل النشاط', href: '/manage/activity', icon: Activity },
    { title: 'الإعدادات', href: '/manage/settings', icon: Settings },
];

/** Matches the current Inertia URL against a nav item (exact for the dashboard, prefix for sections). */
export function isNavItemActive(item: ManageNavItem, currentUrl: string): boolean {
    const path = currentUrl.split('?')[0];

    if (item.href === '/manage') {
        return path === '/manage';
    }

    return path === item.href || path.startsWith(`${item.href}/`);
}
