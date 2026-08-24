/** The AI settings card values, mirroring `App\Settings\AiSettings`. */
export interface AiSettingsValues {
    ai_enabled: boolean;
    search_enabled: boolean;
    assistant_enabled: boolean;
    telegram_ai_enabled: boolean;
    admin_copilot_enabled: boolean;
    admin_assistant_enabled: boolean;
    daily_budget_usd: number;
    per_session_rate_limit: number;
    per_conversation_rate_limit: number;
}

/**
 * The models actually in effect, resolved server-side from config (ai-kit
 * docs/DECISIONS.md #26). Read-only: these are configuration, not operator
 * state, so the card displays them and never submits them.
 */
export interface AiModelsInEffect {
    chat: string;
    chat_reasoning_effort: string;
    vision: string;
    embedding: string;
}

/** The Telegram settings card values (page-management bot settings). */
export interface TelegramSettingsValues {
    allowed_chat_ids: string[];
    auto_delete_messages: boolean;
}
