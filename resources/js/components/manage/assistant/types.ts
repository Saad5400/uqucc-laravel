/**
 * The classified-approval wire cards (ai-kit `ApprovalCards` + uqucc's
 * `category`). Every trust-bearing field is server-derived; the model only
 * contributes `arguments` — which are exactly what a confirmed resume
 * executes.
 */
export interface AssistantApprovalCard {
    kind: 'approval';
    id: string;
    /** The unified action name, e.g. "update_page" or "update_setting". */
    tool: string;
    title: string;
    /** Visual grouping: pages | settings | tutors | users | reviews | … */
    category: string;
    /** Server-derived — a destructive card is one-click, never editable. */
    destructive: boolean;
    undoable: boolean;
    /** Whether the admin may edit the arguments before confirming. */
    editable: boolean;
    arguments: Record<string, unknown>;
    preview: string[];
    /** The Arabic summary of what will execute. */
    reason: string | null;
}

/** An AskUser pause: answered (or dismissed), never "confirmed". */
export interface AssistantQuestionCard {
    kind: 'question';
    id: string;
    question: string;
}

export type AssistantCard = AssistantApprovalCard | AssistantQuestionCard;

/** One per-card decision, keyed by tool-call id in the resume payload. */
export type AssistantDecision = 'approve' | { action: 'reject' } | { action: 'edit'; arguments: Record<string, unknown> };

/** A card plus its client-side lifecycle around the batched resume. */
export interface TrackedCard {
    card: AssistantCard;
    /** Undecided cards are 'pending'; a local decision holds until the batch submits. */
    status: 'pending' | 'decided' | 'submitted';
    decision: AssistantDecision | null;
}
