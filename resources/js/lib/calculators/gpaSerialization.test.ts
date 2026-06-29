import { describe, expect, it } from 'vitest';
import { deserializeCourses, GPA_DATA_VERSION, serializeCourses, type PortableCourse } from './gpaSerialization';

describe('serializeCourses', () => {
    it('wraps courses in a versioned envelope', () => {
        const courses: PortableCourse[] = [{ name: 'Programming', credits: '3', grade: 'A+' }];

        expect(JSON.parse(serializeCourses(courses))).toEqual({
            version: GPA_DATA_VERSION,
            courses,
        });
    });

    it('round-trips through deserializeCourses', () => {
        const courses: PortableCourse[] = [
            { name: 'Calculus', credits: '4', grade: 'B' },
            { name: 'No grade yet', credits: '2', grade: null },
        ];

        expect(deserializeCourses(serializeCourses(courses))).toEqual(courses);
    });
});

describe('deserializeCourses', () => {
    it('reads the current versioned format with string grades', () => {
        const text = JSON.stringify({
            version: 1,
            courses: [{ name: 'CS', credits: '3', grade: 'A+' }],
        });

        expect(deserializeCourses(text)).toEqual([{ name: 'CS', credits: '3', grade: 'A+' }]);
    });

    it('reads the legacy { courses: [{ grade: { value, label } }] } format', () => {
        const legacy = JSON.stringify({
            courses: [
                { name: 'Old Course', credits: '3', grade: { value: 'B+', label: 'B+' } },
                { name: 'Ungraded', credits: '2' },
            ],
        });

        expect(deserializeCourses(legacy)).toEqual([
            { name: 'Old Course', credits: '3', grade: 'B+' },
            { name: 'Ungraded', credits: '2', grade: null },
        ]);
    });

    it('reads a bare array (oldest localStorage shape)', () => {
        const bare = JSON.stringify([{ name: 'Course', credits: '3', grade: { value: 'C', label: 'C' } }]);

        expect(deserializeCourses(bare)).toEqual([{ name: 'Course', credits: '3', grade: 'C' }]);
    });

    it('coerces numeric credits to strings and fills missing fields', () => {
        const text = JSON.stringify({ courses: [{ credits: 3 }] });

        expect(deserializeCourses(text)).toEqual([{ name: '', credits: '3', grade: null }]);
    });

    it('treats an empty-string grade as no grade', () => {
        const text = JSON.stringify({ courses: [{ name: 'X', credits: '3', grade: '' }] });

        expect(deserializeCourses(text)).toEqual([{ name: 'X', credits: '3', grade: null }]);
    });

    it('throws on invalid JSON', () => {
        expect(() => deserializeCourses('not json')).toThrow();
    });

    it('throws when there is no recognizable course list', () => {
        expect(() => deserializeCourses(JSON.stringify({ foo: 'bar' }))).toThrow();
    });
});
