import type { AiKitCard, ApprovalPayload, ClientDecision, QuestionPayload } from '@saad5400/ai-kit/events';

/**
 * The classified-approval wire cards. Every trust-bearing field — including
 * the `fields` form schema each argument renders from — is server-derived by
 * ai-kit's `ApprovalCards`; uqucc adds only `category`, the grouping key its
 * per-category icons read.
 */
export type AssistantApprovalCard = ApprovalPayload & { category: string };

export type AssistantCard = AssistantApprovalCard | QuestionPayload;

/**
 * The card as it arrives inside a timeline segment, where the kit's own
 * union is what the reducer stores. Narrow it with the helpers below rather
 * than re-declaring the shapes.
 */
export const isQuestionCard = (card: AiKitCard): card is QuestionPayload => card.kind === 'question';

export const asApprovalCard = (card: AiKitCard): AssistantApprovalCard => card as AssistantApprovalCard;

/**
 * One card's place in the batched resume. Cards stay visible after a
 * decision — the admin should be able to read what they confirmed — so the
 * decision and the answer text outlive the pending state.
 */
export interface CardDecision {
    /** Undecided cards are 'pending'; a local decision holds until the batch submits. */
    status: 'pending' | 'decided' | 'submitted';
    decision: ClientDecision | null;
    /** What a question card was answered with, for its settled rendering. */
    answer: string | null;
}
