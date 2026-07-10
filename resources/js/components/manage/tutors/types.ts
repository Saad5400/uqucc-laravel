export interface TutorCourse {
    id: number;
    name: string;
}

export interface TutorRow {
    id: number;
    name: string;
    url: string | null;
    courses: TutorCourse[];
}

export interface CourseRow {
    id: number;
    name: string;
    tutors_count: number;
}

/**
 * Wrap Latin/tech tokens (C++, C#, HTML5…) in LTR isolates so they keep their
 * character order inside Arabic course names — trailing `+`/`#` otherwise flip
 * to the wrong side in an RTL context (e.g. «C++» rendering as «++C»).
 */
export function isolateLatinTokens(name: string): string {
    return name.replace(/[A-Za-z][A-Za-z0-9+#.]*/g, '\u2066$&\u2069');
}
