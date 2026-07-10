/** One pending action card, mirroring `AdminPendingAction::toClientPayload()`. */
export interface AssistantProposal {
    id: string;
    type: 'page_change' | 'settings_change';
    summary: string;
    payload: Record<string, unknown>;
    status: 'pending' | 'confirmed' | 'rejected' | 'failed';
    error: string | null;
}
