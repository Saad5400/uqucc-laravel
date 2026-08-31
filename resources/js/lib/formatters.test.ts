import { describe, expect, it } from 'vitest';
import { formatHijriDate } from './formatters';

describe('formatHijriDate', () => {
    // Anchors announced by Saudi Arabia. The plain `islamic` calendar and
    // `islamic-civil` disagree with Umm al-Qura on most of these.
    it.each([
        ['2025-03-01', '١', '١٤٤٦'], // start of Ramadan 1446
        ['2025-06-26', '١', '١٤٤٧'], // Hijri new year 1447
        ['2026-09-27', '١٦', '١٤٤٨'], // reward day, September 2026
    ])('formats %s as an Umm al-Qura date', (gregorian, day, year) => {
        const formatted = formatHijriDate(new Date(`${gregorian}T00:00:00Z`));

        expect(formatted).toContain(day);
        expect(formatted).toContain(year);
    });

    it('matches the Umm al-Qura calendar exactly, not the civil one', () => {
        const formatted = formatHijriDate(new Date('2026-09-27T00:00:00Z'));

        expect(formatted).toBe(
            new Intl.DateTimeFormat('ar-SA-u-ca-islamic-umalqura', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                timeZone: 'Asia/Riyadh',
            }).format(new Date('2026-09-27T00:00:00Z')),
        );
        expect(formatted).not.toContain('١٤ ');
    });
});
