import { describe, expect, it } from 'vitest';
import { buildJoinMessage, withPrefilledMessage, type JoinRequest } from './joinMessage';

const request: JoinRequest = {
    cohort: 'دفعة ٤٨',
    section: 'شطر الطلاب',
    major: 'علوم الحاسب',
    branch: 'الفرع الرئيسي — مكة المكرمة',
    group: 'قروب علوم الحاسب',
    supervisor: 'أحمد',
};

describe('buildJoinMessage', () => {
    it('addresses the supervisor by name', () => {
        expect(buildJoinMessage(request)).toContain('السلام عليكم أحمد');
    });

    it('names the group being asked for', () => {
        expect(buildJoinMessage(request)).toContain('«قروب علوم الحاسب»');
    });

    it('carries every fact the student gave in step 1', () => {
        const message = buildJoinMessage(request);

        for (const fact of [request.cohort, request.section, request.major, request.branch]) {
            expect(message).toContain(fact);
        }
    });

    it('says the acceptance image is attached and what it shows', () => {
        expect(buildJoinMessage(request)).toContain('مرفق صورة القبول النهائي من البوابة الأكاديمية');
    });

    it('keeps the facts on their own lines, so they stay scannable in a bubble', () => {
        expect(buildJoinMessage(request)).toContain('\nالدفعة: دفعة ٤٨\nالشطر: شطر الطلاب\n');
    });
});

describe('withPrefilledMessage', () => {
    it('appends the draft as a text parameter', () => {
        expect(withPrefilledMessage('https://wa.me/966501234567', 'مرحبا')).toBe('https://wa.me/966501234567?text=%D9%85%D8%B1%D8%AD%D8%A8%D8%A7');
    });

    it('encodes spaces as %20, never as +, which WhatsApp would show literally', () => {
        const url = withPrefilledMessage('https://t.me/ahmad', 'a b');

        expect(url).toContain('%20');
        expect(url).not.toContain('+');
    });

    it('encodes the line breaks the message is built from', () => {
        expect(withPrefilledMessage('https://t.me/ahmad', 'a\nb')).toBe('https://t.me/ahmad?text=a%0Ab');
    });

    it('joins onto a url that already carries a query', () => {
        expect(withPrefilledMessage('https://t.me/ahmad?x=1', 'hi')).toBe('https://t.me/ahmad?x=1&text=hi');
    });
});
