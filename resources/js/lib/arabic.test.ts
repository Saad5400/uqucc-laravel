import { describe, expect, it } from 'vitest';
import { arabicSupervisors, normalizeArabic } from './arabic';

describe('normalizeArabic', () => {
    it('folds the alef forms so a plain spelling matches a hamza one', () => {
        expect(normalizeArabic('الأمن السيبراني')).toBe(normalizeArabic('الامن السيبراني'));
        expect(normalizeArabic('إبراهيم')).toBe(normalizeArabic('ابراهيم'));
    });

    it('folds ta marbuta, alef maksura and the hamza carriers', () => {
        expect(normalizeArabic('دفعة')).toBe(normalizeArabic('دفعه'));
        expect(normalizeArabic('مصطفى')).toBe(normalizeArabic('مصطفي'));
        expect(normalizeArabic('مسؤول')).toBe(normalizeArabic('مسوول'));
    });

    it('strips diacritics and tatweel', () => {
        expect(normalizeArabic('بَنان')).toBe('بنان');
        expect(normalizeArabic('حاســـب')).toBe('حاسب');
    });

    it('keeps Arabic-Indic digits, which sit just past the tashkeel range', () => {
        expect(normalizeArabic('دفعة ٤٨')).toBe('دفعه ٤٨');
        expect(normalizeArabic('٠١٢٣٤٥٦٧٨٩')).toBe('٠١٢٣٤٥٦٧٨٩');
    });

    it('collapses whitespace and lowercases latin', () => {
        expect(normalizeArabic('  Data   Science  ')).toBe('data science');
    });
});

describe('arabicSupervisors', () => {
    it('agrees with the number', () => {
        expect(arabicSupervisors(1)).toBe('مشرف واحد');
        expect(arabicSupervisors(2)).toBe('مشرفان');
        expect(arabicSupervisors(5)).toBe('5 مشرفين');
        expect(arabicSupervisors(20)).toBe('20 مشرف');
    });
});
