/** The AI settings card values, mirroring `App\Settings\AiSettings`. */
export interface AiSettingsValues {
    ai_enabled: boolean;
    search_enabled: boolean;
    assistant_enabled: boolean;
    telegram_ai_enabled: boolean;
    admin_copilot_enabled: boolean;
    chat_model: string;
    vision_model: string;
    embedding_model: string;
    daily_budget_usd: number;
    per_session_rate_limit: number;
    per_conversation_rate_limit: number;
}

/** The Telegram settings card values (page-management bot settings). */
export interface TelegramSettingsValues {
    allowed_chat_ids: string[];
    auto_delete_messages: boolean;
}
