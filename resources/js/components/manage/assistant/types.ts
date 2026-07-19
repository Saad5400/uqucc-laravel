/** One pending action card, mirroring `AdminPendingAction::toClientPayload()`. */
export interface AssistantProposal {
    id: string;
    /** The unified action name, e.g. "update_page" or "update_setting". */
    type: string;
    /** Visual grouping: pages | settings | tutors | users | reviews | … */
    category: string;
    summary: string;
    /** Normalized fields worth showing under the summary. */
    details: Record<string, unknown>;
    status: 'pending' | 'confirmed' | 'rejected' | 'failed';
    error: string | null;
}
