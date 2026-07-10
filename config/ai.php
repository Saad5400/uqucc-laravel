<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Providers (the swap seam)
    |--------------------------------------------------------------------------
    |
    | The only place a concrete AI provider is named. Callers never reference
    | OpenRouter directly — swapping providers later means a new stanza here
    | plus a gateway/driver behind the same contracts, not a rewrite.
    |
    | laravel/ai merges this file over its vendor config (mergeConfigFrom is a
    | shallow, top-level merge), so the keys below intentionally REPLACE the
    | vendor defaults: `providers` drops the stock multi-provider list because
    | this application routes everything through OpenRouter. The "openrouter"
    | driver itself is re-registered by App\Providers\AiServiceProvider to use
    | our ReasoningOpenRouterGateway (streams reasoning deltas and captures
    | OpenRouter's exact usage.cost).
    |
    */

    'default' => env('AI_DEFAULT_PROVIDER', 'openrouter'),
    'default_for_embeddings' => env('AI_EMBEDDING_PROVIDER', 'openrouter'),

    'providers' => [
        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
            'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat / drafting
    |--------------------------------------------------------------------------
    |
    | The model used for conversational and content-drafting tasks (page copy,
    | announcements, summaries). Model ids are OpenRouter slugs.
    |
    */

    'chat' => [
        'model' => env('AI_CHAT_MODEL', 'google/gemini-3.5-flash'),
        'reasoning_effort' => env('AI_CHAT_REASONING_EFFORT', 'medium'),
        'timeout' => (int) env('AI_CHAT_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversations
    |--------------------------------------------------------------------------
    |
    | Consumed by laravel/ai's conversation store (the assistant's anonymous,
    | session-owned threads). Title generation is OFF: the package default
    | fires an extra model call per new conversation to name it — for an
    | anonymous student chat the truncated first message is plenty, costs
    | nothing, and keeps faked-agent tests from consuming an extra response.
    |
    */

    'conversations' => [
        'generate_title' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Vision extraction
    |--------------------------------------------------------------------------
    |
    | The "eyes only" pass that turns uploaded images (posters, screenshots,
    | transcripts) into structured text. Kept separate from chat so a cheap
    | vision-capable model can be pinned independently.
    |
    */

    'vision' => [
        'model' => env('AI_VISION_MODEL', 'google/gemini-2.5-flash'),
        'timeout' => (int) env('AI_VISION_TIMEOUT', 45),
        'max_tokens' => (int) env('AI_VISION_MAX_TOKENS', 2500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Consumed by App\Ai\Embeddings\TextEmbedder. `driver` picks the concrete
    | backend: "openrouter" (real endpoint) or "fake" (deterministic, offline —
    | for tests and keyless local development). `dimensions` MUST match the
    | vector(N) column of any pgvector table that stores these embeddings.
    |
    */

    'embeddings' => [
        'driver' => env('AI_EMBEDDING_DRIVER', 'openrouter'),
        'model' => env('AI_EMBEDDING_MODEL', 'openai/text-embedding-3-small'),
        'dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 1536),
        'chunk_words' => (int) env('AI_CHUNK_WORDS', 400),
        'chunk_overlap_words' => (int) env('AI_CHUNK_OVERLAP_WORDS', 60),
    ],

];
