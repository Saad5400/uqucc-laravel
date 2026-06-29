/**
 * Serialization for the GPA calculator's course list.
 *
 * The on-the-wire shape is intentionally small and grade is stored as a plain
 * string (e.g. "A+"), not the `{ value, label }` object the UI binds to. This
 * keeps exported payloads readable and stable.
 *
 * `deserializeCourses` is tolerant on input so it can read:
 *   - the current format:  { version: 1, courses: [{ name, credits, grade }] }
 *   - the legacy format:   { courses: [{ name, credits, grade: { value, label } }] }
 *   - a bare array:        [{ name, credits, grade }]
 */

export interface PortableCourse {
    name: string;
    credits: string;
    grade: string | null;
}

export interface GpaExportPayload {
    version: number;
    courses: PortableCourse[];
}

export const GPA_DATA_VERSION = 1;

/** Pull a grade string out of either the new (string) or legacy ({ value }) shape. */
const normalizeGrade = (grade: unknown): string | null => {
    if (typeof grade === 'string') {
        return grade.trim() || null;
    }

    if (grade && typeof grade === 'object' && 'value' in grade) {
        const value = (grade as { value: unknown }).value;
        return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
    }

    return null;
};

/** Coerce any loosely-typed record into a clean PortableCourse. */
const normalizeCourse = (course: unknown): PortableCourse => {
    const record = (course ?? {}) as Record<string, unknown>;

    return {
        name: typeof record.name === 'string' ? record.name : '',
        credits: record.credits != null ? String(record.credits) : '',
        grade: normalizeGrade(record.grade),
    };
};

export const serializeCourses = (courses: PortableCourse[]): string =>
    JSON.stringify({ version: GPA_DATA_VERSION, courses } satisfies GpaExportPayload);

/**
 * Parse a serialized payload into PortableCourses, accepting the current,
 * legacy, and bare-array shapes. Throws if the text is not valid JSON or does
 * not contain a recognizable list of courses.
 */
export const deserializeCourses = (text: string): PortableCourse[] => {
    const parsed: unknown = JSON.parse(text);

    const rawCourses = Array.isArray(parsed) ? parsed : (parsed as { courses?: unknown })?.courses;

    if (!Array.isArray(rawCourses)) {
        throw new Error('Invalid GPA data: expected a list of courses.');
    }

    return rawCourses.map(normalizeCourse);
};
