import { describe, expect, it } from 'vitest';
import { visibleNavItems } from './nav';

describe('visibleNavItems', () => {
    it('hides the settings section from panel users without manage-settings', () => {
        const editor = visibleNavItems(['edit-content', 'view-activity-logs']).map((item) => item.href);

        expect(editor).not.toContain('/manage/settings');
        expect(editor).toContain('/manage/pages');
    });

    it('shows the settings section to admins', () => {
        const admin = visibleNavItems(['edit-content', 'manage-settings']).map((item) => item.href);

        expect(admin).toContain('/manage/settings');
    });
});
