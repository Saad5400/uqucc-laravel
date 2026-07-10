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
