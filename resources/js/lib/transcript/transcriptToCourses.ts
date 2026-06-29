import type { PortableCourse } from '@/lib/calculators/gpaSerialization';
import type { TranscriptResult } from './uquTranscriptParser';

/**
 * Maps transcript letter grades onto the grades the GPA calculator understands.
 * Only grades that actually affect the 4.0 GPA are listed. `DN` (Denied) carries
 * the same zero weight as `F` on the official scale, so it folds into `F`.
 * Non-counted grades (IP, IC, W, E, …) are intentionally absent and skipped.
 */
const GPA_GRADE_MAP: Record<string, string> = {
    'A+': 'A+',
    A: 'A',
    'B+': 'B+',
    B: 'B',
    'C+': 'C+',
    C: 'C',
    'D+': 'D+',
    D: 'D',
    F: 'F',
    DN: 'F',
};

/**
 * Flattens a parsed transcript into the GPA calculator's importable course list.
 *
 * Courses are taken across every semester in document order. A course is
 * included only when its grade contributes to the GPA; in-progress, withdrawn,
 * and other non-counted grades are dropped so they do not distort the average.
 */
export const transcriptToCourses = (result: TranscriptResult): PortableCourse[] => {
    const courses: PortableCourse[] = [];

    for (const semester of result.semesters ?? []) {
        for (const course of semester.courses ?? []) {
            const grade = GPA_GRADE_MAP[course.grade];
            if (!grade) {
                continue;
            }

            courses.push({
                name: (course.name_ar || course.name_en || course.code || '').trim(),
                credits: course.credit_hours != null ? String(course.credit_hours) : '',
                grade,
            });
        }
    }

    return courses;
};
