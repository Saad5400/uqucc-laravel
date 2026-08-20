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
    | ai-kit's ReasoningOpenRouterGateway (streams reasoning deltas and captures
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
    | `model` is deliberately EMPTY by default: the fleet's shared chat model
    | lives in the kit (ai-kit docs/DECISIONS.md #21, `ai-kit.chat.model`) and
    | uqucc inherits it rather than pinning a second copy of the slug here.
    | Set AI_CHAT_MODEL to override the shared default for this app only. The
    | whole chain — operator setting, this key, the kit default — is resolved
    | in one place, {@see \App\Settings\AiSettings::chatModel()}.
    |
    | `reasoning_effort` is 'low' because the assistant is an interactive
    | surface and some builds default to 'high' server-side — every caller
    | must therefore send the effort explicitly to get the faster path. The
    | shared Gemini Flash Lite default supports it.
    |
    | `queue` is where a resumable assistant turn runs (both the student and
    | the admin surface). It gets a queue of its OWN rather than sharing one:
    | `default` is a single worker with no --timeout, so turns would serialize
    | behind each other, block every other default-queue job, and be killed at
    | 60s; and `ai` carries multi-minute corpus extraction and ingestion, which
    | an interactive reply must never wait behind. Static on purpose — like
    | queue.php's `retry_after` it is a fixed property of our worker topology
    | (nixpacks.toml's `worker-ai-chat`), not a per-environment knob.
    |
    */

    'chat' => [
        'model' => env('AI_CHAT_MODEL'),
        'reasoning_effort' => env('AI_CHAT_REASONING_EFFORT', 'low'),
        'timeout' => (int) env('AI_CHAT_TIMEOUT', 60),
        'queue' => 'ai-chat',
    ],

    /*
    |--------------------------------------------------------------------------
    | End-user assistant surfaces
    |--------------------------------------------------------------------------
    |
    | Settings shared by every end-user AI surface (the web chat and the
    | Telegram bot) so they stay unified. `disclaimer` is the single source of
    | truth for the "AI-generated, may be wrong" notice appended to each reply
    | in code — NOT produced by the model — on both surfaces.
    |
    */

    'assistant' => [
        'disclaimer' => env('AI_ASSISTANT_DISCLAIMER', 'المحتوى مولَّد بالذكاء الاصطناعي وقد يحتوي على أخطاء — تحقّق من المصادر عند الحاجة.'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authoring (the "smart" tier)
    |--------------------------------------------------------------------------
    |
    | Used for heavyweight content work — drafting a page from an uploaded
    | document, proposing revisions to an existing page, stale-content
    | analysis. Deliberately a stronger reasoning model than chat: these are
    | rare, admin-triggered, review-gated calls where quality beats latency.
    |
    */

    'authoring' => [
        'model' => env('AI_AUTHORING_MODEL', 'deepseek/deepseek-v4-pro-0813'),
        'reasoning_effort' => env('AI_AUTHORING_REASONING_EFFORT', 'high'),
        'timeout' => (int) env('AI_AUTHORING_TIMEOUT', 180),
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
        'model' => env('AI_VISION_MODEL', 'google/gemini-3.1-flash-lite'),
        'timeout' => (int) env('AI_VISION_TIMEOUT', 45),
        'document_timeout' => (int) env('AI_VISION_DOCUMENT_TIMEOUT', 180),
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
