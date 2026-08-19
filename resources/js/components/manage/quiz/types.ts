export type QuizStatus = 'ready' | 'posted' | 'closed';

export interface Quiz {
    id: number;
    quiz_topic_id: number | null;
    quiz_date: string;
    /** The question as a sanitized HTML fragment (preamble + code + question), rendered to an image. */
    question: string;
    options: string[];
    correct_option: number;
    explanation: string | null;
    hint: string | null;
    obvious_hint: string | null;
    status: QuizStatus;
    /** A one-day posting time («HH:MM»), or null to follow the default. */
    post_time: string | null;
    topic: string | null;
    posted_at: string | null;
    answers_count: number;
    correct_answers_count: number;
}

/** When the daily question goes out: the default, and today's own override. */
export interface Schedule {
    /** The default posting time for every day, as «HH:MM». */
    post_time: string;
    /** Today's own posting time when it was rescheduled on its own. */
    today_post_time: string | null;
    /** The moment today's question goes out, resolved server-side. */
    today_posts_at: string;
}

/** A day in the upcoming queue, without the question body — just the schedule. */
export interface QueuedDay {
    id: number;
    quiz_date: string;
    status: QuizStatus;
    /** A one-day posting time («HH:MM»), or null to follow the default. */
    post_time: string | null;
    topic: string | null;
}

export interface Topic {
    id: number;
    name: string;
    prompt_hint: string | null;
    is_spotlight: boolean;
    is_active: boolean;
    last_used_at: string | null;
    /** True while the running rotation cycle still owes this topic a turn — the pool the group's topic vote draws its ballot from. */
    awaiting_turn: boolean;
}

/** Length caps, mirrored from the authoring constants server-side. */
export interface Limits {
    question: number;
    option: number;
    explanation: number;
    hint: number;
}

/** One status vocabulary for questions, shared by the front door and the archive. */
export const statusBadges: Record<QuizStatus, { label: string; variant: 'secondary' | 'default' | 'outline' }> = {
    ready: { label: 'بانتظار النشر', variant: 'secondary' },
    posted: { label: 'منشور الآن', variant: 'default' },
    closed: { label: 'مغلق', variant: 'outline' },
};

/**
 * Why a question can no longer be edited or deleted, or null while it still
 * can. Posted questions are history the scoring depends on.
 */
export function lockReason(quiz: Quiz): string | null {
    return quiz.status === 'ready' ? null : 'لا يمكن تعديل سؤال أو حذفه بعد نشره — الإجابات والنقاط محسوبة عليه.';
}
