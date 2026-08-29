export type PollStatus = 'ready' | 'posted' | 'closed';

/** One opinion poll, the same shape everywhere the page shows it. */
export interface Poll {
    id: number;
    poll_date: string;
    question: string;
    options: string[];
    /** The angle a generated poll was written from, or null when hand-written. */
    theme: string | null;
    status: PollStatus;
    /** A one-day posting time («HH:MM»), or null to follow the default. */
    post_time: string | null;
    /** Final per-option vote counts — all zeros until the poll closes. */
    results: number[];
    total_votes: number;
    posted_at: string | null;
    /** When the votes stop being taken and the result is announced. */
    closes_at: string | null;
    closed_at: string | null;
}

/** A day in the queue, without the result — just enough to scan the schedule. */
export interface QueuedPoll {
    id: number;
    poll_date: string;
    status: PollStatus;
    question: string;
    theme: string | null;
    post_time: string | null;
}

/** Telegram's poll caps, mirrored from the model constants server-side. */
export interface Limits {
    question: number;
    option: number;
    min_options: number;
    max_options: number;
    max_open_hours: number;
}

/** One angle the author can write from. */
export interface Theme {
    value: string;
    label: string;
}

/** A ready-made poll the editor offers as a starting point. */
export interface Suggestion {
    question: string;
    options: string[];
}

export const statusBadges: Record<PollStatus, { label: string; variant: 'secondary' | 'default' | 'outline' }> = {
    ready: { label: 'بانتظار النشر', variant: 'secondary' },
    posted: { label: 'مفتوح للتصويت', variant: 'default' },
    closed: { label: 'انتهى', variant: 'outline' },
};

/** Each option with its share of the vote, ranked — the way a result reads. */
export function rankedResults(poll: Poll): { option: string; votes: number; percent: number }[] {
    return poll.options
        .map((option, index) => ({
            option,
            votes: poll.results[index] ?? 0,
            percent: poll.total_votes === 0 ? 0 : Math.round(((poll.results[index] ?? 0) / poll.total_votes) * 100),
        }))
        .sort((a, b) => b.votes - a.votes);
}
