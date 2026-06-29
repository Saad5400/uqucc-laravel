import { describe, expect, it } from 'vitest';
import { transcriptToCourses } from './transcriptToCourses';
import { UquTranscriptParser } from './uquTranscriptParser';

// Arabic course name used to verify right-side Arabic-name recovery.
const NAME_AR = 'برمجة';

// A compact transcript laid out like the real `pdftotext -layout` output:
// English columns are authoritative, the Arabic name trails after a wide gap.
const layout = [
    '                                   Student ID: 444001234',
    '   Name: John Doe Smith',
    '   GPA : 3.50',
    '',
    'First Semester 1445/1446    Status : Good',
    '   Faculty : Computer Science',
    '   Dept : Computer Science',
    'Course Code   Course Name                         Crd  Pass  Deg  Grade  Points',
    `CS101    Programming I                            3  3  95  A+  12.00          ${NAME_AR}`,
    'MATH102  Calculus                                 3  3  85  B+  10.50',
    'CS200    Data Structures                          3  3  90  A   10.00',
    'S.GPA: 3.50    9  9  sum:  32.50  - Good',
    'Ac. GPA : 3.50',
    '',
    'Second Semester 1445/1446',
    'Course Code   Course Name',
    'ENG099   English                                  3  0  IP  0.00',
    'PHY100   Physics                                  3  0  DN  0.00',
    'S.GPA: 0.00',
    '',
    'Crd Hrs: 12  Passed Hrs: 9  Accum GPA: 3.50  Points: 32.50',
].join('\n');

describe('UquTranscriptParser.parseLayoutText', () => {
    const parser = new UquTranscriptParser();
    const result = parser.parseLayoutText(layout);

    it('extracts the student header', () => {
        expect(result.student.student_id).toBe('444001234');
        expect(result.student.name_en).toBe('John Doe Smith');
        expect(result.student.gpa).toBe(3.5);
    });

    it('groups courses into their semesters', () => {
        expect(result.semesters).toHaveLength(2);
        expect(result.semesters[0].term).toBe('First Semester');
        expect(result.semesters[0].academic_year).toBe('1445/1446');
        expect(result.semesters[0].courses.map((c) => c.code)).toEqual(['CS101', 'MATH102', 'CS200']);
        expect(result.semesters[1].courses.map((c) => c.code)).toEqual(['ENG099', 'PHY100']);
    });

    it('reads English columns and the trailing Arabic name', () => {
        const cs101 = result.semesters[0].courses[0];
        expect(cs101.credit_hours).toBe(3);
        expect(cs101.grade).toBe('A+');
        expect(cs101.points).toBe(12);
        expect(cs101.points_valid).toBe(true);
        expect(cs101.name_en).toBe('Programming I');
        expect(cs101.name_ar).toBe(NAME_AR);
    });

    it('flags a Points value that disagrees with grade * credits', () => {
        const cs200 = result.semesters[0].courses[2];
        expect(cs200.grade).toBe('A');
        expect(cs200.points_valid).toBe(false);
        expect(parser.warnings.some((w) => w.includes('CS200'))).toBe(true);
    });

    it('parses the final totals block', () => {
        expect(result.totals).toEqual({
            credit_hours: 12,
            passed_hours: 9,
            cumulative_gpa: 3.5,
            points: 32.5,
        });
    });

    it('feeds transcriptToCourses the GPA-affecting courses only', () => {
        expect(transcriptToCourses(result)).toEqual([
            { name: NAME_AR, credits: '3', grade: 'A+' },
            { name: 'Calculus', credits: '3', grade: 'B+' },
            { name: 'Data Structures', credits: '3', grade: 'A' },
            { name: 'Physics', credits: '3', grade: 'F' },
        ]);
    });
});
