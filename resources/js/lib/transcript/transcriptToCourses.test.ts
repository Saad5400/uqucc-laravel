import { describe, expect, it } from 'vitest';
import { transcriptToCourses } from './transcriptToCourses';
import type { TranscriptCourse, TranscriptResult } from './uquTranscriptParser';

// Minimal course builder so fixtures stay readable; only the fields the mapper
// reads need to be supplied.
const course = (partial: Partial<TranscriptCourse>): TranscriptCourse => ({
    code: 'XX000',
    name_en: null,
    name_ar: null,
    credit_hours: 3,
    pass_hours: 3,
    degree: null,
    grade: 'A',
    grade_ar: null,
    grade_weight: null,
    points: 0,
    points_valid: null,
    ...partial,
});

const result = (...courseLists: TranscriptCourse[][]): TranscriptResult =>
    ({
        student: {} as TranscriptResult['student'],
        semesters: courseLists.map((courses) => ({ courses }) as unknown as TranscriptResult['semesters'][number]),
        totals: null,
        grade_scale: {},
    }) as TranscriptResult;

describe('transcriptToCourses', () => {
    it('flattens courses across all semesters in order', () => {
        const data = result([course({ code: 'CS101', grade: 'A+', credit_hours: 3 })], [course({ code: 'CS102', grade: 'B', credit_hours: 4 })]);

        expect(transcriptToCourses(data)).toEqual([
            { name: 'CS101', credits: '3', grade: 'A+' },
            { name: 'CS102', credits: '4', grade: 'B' },
        ]);
    });

    it('prefers the Arabic name, then English, then the course code', () => {
        const data = result([
            course({ code: 'CS101', name_ar: 'arabic-name', name_en: 'English Name', grade: 'A' }),
            course({ code: 'CS102', name_ar: null, name_en: 'English Only', grade: 'A' }),
            course({ code: 'CS103', name_ar: null, name_en: null, grade: 'A' }),
        ]);

        expect(transcriptToCourses(data).map((c) => c.name)).toEqual(['arabic-name', 'English Only', 'CS103']);
    });

    it('folds DN into F (both are zero-weight, counted grades)', () => {
        const data = result([course({ code: 'CS101', grade: 'DN', credit_hours: 3 })]);

        expect(transcriptToCourses(data)).toEqual([{ name: 'CS101', credits: '3', grade: 'F' }]);
    });

    it('skips grades that do not count toward the GPA', () => {
        const data = result([
            course({ code: 'IN', grade: 'IP' }),
            course({ code: 'WD', grade: 'W' }),
            course({ code: 'EX', grade: 'E' }),
            course({ code: 'NPS', grade: 'NP' }),
            course({ code: 'KEEP', grade: 'C+' }),
        ]);

        expect(transcriptToCourses(data)).toEqual([{ name: 'KEEP', credits: '3', grade: 'C+' }]);
    });

    it('returns an empty list when there are no semesters', () => {
        expect(transcriptToCourses(result())).toEqual([]);
    });
});
