<?php

namespace App\Services\Telegram\Handlers;

use App\Ai\Agents\StudentAssistant;
use App\Ai\Chat\AttachmentContext;
use App\Ai\Chat\ChatAttachmentTextExtractor;
use App\Ai\Chat\SessionOwner;
use App\Ai\Spend\SpendLedger;
use App\Models\Ai\ChatAttachment;
use App\Models\TelegramChatSetting;
use App\Services\TelegramMarkdownService;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Models\Conversation;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Document;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\PhotoSize;
use Throwable;

/**
 * The Telegram transport of the shared {@see StudentAssistant} — the same
 * agent, tools, and Arabic instructions as the web chat, replying in chats
 * where the assistant is activated.
 *
 * Layered gates, in order: AiSettings telegram feature toggle → the chat's
 * own activation row ({@see TelegramChatSetting}, default OFF — /ai_on) →
 * group addressing (mention or reply-to-bot, so the bot never answers every
 * group message) → per-CHAT rate limits (burst + operator daily quota) →
 * the daily spend budget via the {@see SpendLedger}.
 *
 * Conversation continuity is per chat: the laravel/ai conversation id lives
 * on the chat's settings row and /ai_new resets it. Photos and PDF documents
 * sent with a caption are downloaded, extracted through the budget-gated
 * {@see ChatAttachmentTextExtractor} (never into the public corpus), and
 * injected as context for that turn via {@see AttachmentContext}.
 */
class AiChatHandler extends BaseHandler
{
    /** Spend-ledger feature key for Telegram assistant turns. */
    protected const FEATURE = 'telegram';

    /** Telegram's hard per-message character limit. */
    protected const TELEGRAM_MESSAGE_LIMIT = 4096;

    /** Burst limit: messages per minute per chat. */
    protected const BURST_LIMIT = 5;

    /** Cache key for the bot's username (avoids a getMe call per message). */
    protected const BOT_USERNAME_CACHE_KEY = 'telegram_bot_username';

    /** The legacy DeepSeekChatHandler command, kept as an explicit ask. */
    protected const LEGACY_ASK_PATTERN = '/^اسال سيك\s+(.+)$/us';

    /**
     * Prefixes and exact phrases owned by the other handlers — the assistant
     * must not double-answer them in activated chats.
     */
    protected const FOREIGN_COMMAND_PATTERNS = [
        '/^دليل\s+/u',
        '/^بحث\s+/u',
        '/^قوقل\s+/u',
        '/^قيم\s+/u',
        '/^شغل بايثون\s/u',
        '/^شغل جافا\s/u',
        '/^تعديل\s+/u',
        '/^(الفهرس|رابط|إلغاء|الغاء)$/u',
        '/^(تسجيل دخول|تسجيل الدخول|تسجيل خروج|تسجيل الخروج)$/u',
        '/^(أضف صفحة|اضف صفحة|حذف صفحة|الصفحات الذكية)$/u',
    ];

    /**
     * Stateful handlers (using the BaseHandler state cache) whose pending
     * flows must win over the assistant for the user's next message.
     */
    protected const STATEFUL_HANDLERS = [
        PythonExecutionHandler::class,
        JavaExecutionHandler::class,
    ];

    public function __construct(
        Api $telegram,
        protected AiSettings $settings,
        protected SpendLedger $ledger,
        protected ChatAttachmentTextExtractor $extractor,
        protected AttachmentContext $attachmentContext,
    ) {
        parent::__construct($telegram);
    }

    public function handle(Message $message): void
    {
        if (! $this->settings->isFeatureEnabled(self::FEATURE)) {
            return;
        }

        $chatId = (int) $message->getChat()->getId();
        $chatSettings = TelegramChatSetting::forChat($chatId);

        if ($chatSettings === null || ! $chatSettings->ai_enabled) {
            return;
        }

        $prompt = $this->promptFrom($message);

        if ($prompt === null || $prompt === '') {
            return;
        }

        if ($this->anotherHandlerAwaitsInput((int) $message->getFrom()->getId())) {
            return;
        }

        if ($this->isGroupChat($message) && ! $this->isAddressedToBot($message)) {
            return;
        }

        $prompt = $this->stripBotMention($prompt);

        if (trim($prompt) === '') {
            return;
        }

        if (! $this->passesRateLimits($message, $chatId)) {
            return;
        }

        if (! $this->ledger->hasBudgetRemaining()) {
            $this->reply($message, $this->ledger->budgetExhaustedMessage());

            return;
        }

        $this->trackCommand($message, 'ai_chat');

        $attachmentSource = $this->attachmentSource($message);

        $placeholder = $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $attachmentSource !== null ? 'جاري قراءة الملف…' : 'جاري المعالجة…',
            'reply_to_message_id' => $message->getMessageId(),
        ]);

        $attachment = null;

        try {
            if ($attachmentSource !== null) {
                $attachment = $this->extractAttachment($message, $chatId, $attachmentSource);

                if ($attachment === null) {
                    $this->editMessage($chatId, $placeholder->getMessageId(), 'تعذر قراءة الملف — تأكد أنه صورة أو ملف PDF يحتوي نصاً مقروءاً، ثم أعد المحاولة.');

                    return;
                }

                $prompt = $this->attachmentContext->wrap($prompt, collect([$attachment]));
            }

            $this->runAssistantTurn($message, $chatSettings, $prompt, $placeholder->getMessageId());
        } finally {
            $attachment?->delete();
        }
    }

    /**
     * The user's ask for this turn: the message text (or the media caption),
     * with the legacy "اسال سيك" prefix stripped; null when this message is
     * not an AI turn (a /command or another handler's command).
     */
    protected function promptFrom(Message $message): ?string
    {
        $raw = $message->getText() ?? $message->getCaption();
        $content = is_string($raw) ? trim($raw) : '';

        if ($content === '' || str_starts_with($content, '/')) {
            return null;
        }

        if (preg_match(self::LEGACY_ASK_PATTERN, $content, $matches) === 1) {
            return trim($matches[1]);
        }

        foreach (self::FOREIGN_COMMAND_PATTERNS as $pattern) {
            if (preg_match($pattern, $content) === 1) {
                return null;
            }
        }

        return $content;
    }

    /**
     * In groups the bot only answers when addressed: the legacy "اسال سيك"
     * prefix, a reply to one of the bot's messages, or an @mention.
     */
    protected function isAddressedToBot(Message $message): bool
    {
        $raw = $message->getText() ?? $message->getCaption();
        $content = is_string($raw) ? trim($raw) : '';

        if (preg_match(self::LEGACY_ASK_PATTERN, $content) === 1) {
            return true;
        }

        $repliedTo = $message->getReplyToMessage();

        if ($repliedTo && $repliedTo->getFrom()?->getIsBot()
            && strcasecmp((string) $repliedTo->getFrom()->getUsername(), $this->botUsername()) === 0) {
            return true;
        }

        $username = $this->botUsername();

        return $username !== '' && mb_stripos($content, '@'.$username) !== false;
    }

    protected function stripBotMention(string $prompt): string
    {
        $username = $this->botUsername();

        if ($username === '') {
            return $prompt;
        }

        return trim((string) preg_replace('/@'.preg_quote($username, '/').'/iu', '', $prompt));
    }

    /**
     * The bot's username, cached for a day so groups don't cost a getMe call
     * per message.
     */
    protected function botUsername(): string
    {
        return (string) Cache::remember(
            self::BOT_USERNAME_CACHE_KEY,
            now()->addDay(),
            fn (): string => (string) $this->telegram->getMe()->getUsername(),
        );
    }

    /**
     * Rate limits are PER CHAT (per conversation), not per user: a small
     * burst limit plus the operator's daily quota. When a window is
     * exhausted the chat gets ONE Arabic notice per window, then silence.
     */
    protected function passesRateLimits(Message $message, int $chatId): bool
    {
        $burstKey = 'telegram-ai-burst:'.$chatId;

        if (RateLimiter::attempts($burstKey) >= self::BURST_LIMIT) {
            $this->notifyLimitOnce(
                $message,
                'telegram-ai-burst-notice:'.$chatId,
                max(1, RateLimiter::availableIn($burstKey)),
                'رسائل كثيرة خلال وقت قصير — انتظر دقيقة ثم أعد المحاولة. ⏳',
            );

            return false;
        }

        $dailyKey = 'telegram-ai-daily:'.$chatId;
        $dailyLimit = max(1, $this->settings->per_conversation_rate_limit);

        if (RateLimiter::attempts($dailyKey) >= $dailyLimit) {
            $this->notifyLimitOnce(
                $message,
                'telegram-ai-daily-notice:'.$chatId,
                max(60, (int) now()->secondsUntilEndOfDay()),
                'وصلت هذه المحادثة إلى حدها اليومي من رسائل المساعد الذكي. عد غداً وسيسعدنا مساعدتك. 🌙',
            );

            return false;
        }

        RateLimiter::hit($burstKey, 60);
        RateLimiter::hit($dailyKey, max(60, (int) now()->secondsUntilEndOfDay()));

        return true;
    }

    protected function notifyLimitOnce(Message $message, string $cacheKey, int $ttlSeconds, string $notice): void
    {
        if (Cache::add($cacheKey, true, $ttlSeconds)) {
            $this->reply($message, $notice);
        }
    }

    /**
     * Run the turn against the shared assistant, continuing the chat's stored
     * conversation, and edit the placeholder with the reply. Spend is
     * captured from the gateway's Context costs exactly like the web chat.
     */
    protected function runAssistantTurn(Message $message, TelegramChatSetting $chatSettings, string $prompt, int $placeholderMessageId): void
    {
        $chatId = (int) $chatSettings->chat_id;

        $this->ledger->clearContextCosts();

        $owner = new SessionOwner('telegram:'.$chatId);

        $agent = StudentAssistant::make();
        $conversationId = $this->continuableConversationId($chatSettings, $owner);
        $agent = $conversationId !== null
            ? $agent->continue($conversationId, $owner)
            : $agent->forUser($owner);

        try {
            $response = $agent->prompt($prompt);

            $this->recordSpend($response->usage ?? null);

            $chatSettings->update(['conversation_id' => $response->conversationId ?? $conversationId]);

            $this->sendReply($chatId, $placeholderMessageId, trim((string) $response->text));
        } catch (Throwable $exception) {
            report($exception);

            $this->recordSpend(null);

            $this->editMessage($chatId, $placeholderMessageId, 'حدث خطأ أثناء توليد الرد. حاول مرة أخرى.');
        }
    }

    /**
     * Continue only a conversation that still exists and belongs to this
     * chat's owner key; a pruned or foreign id starts a fresh thread.
     */
    protected function continuableConversationId(TelegramChatSetting $chatSettings, SessionOwner $owner): ?string
    {
        $conversationId = $chatSettings->conversation_id;

        if ($conversationId === null || $conversationId === '') {
            return null;
        }

        $exists = Conversation::query()
            ->whereKey($conversationId)
            ->where('user_id', $owner->id)
            ->exists();

        return $exists ? $conversationId : null;
    }

    protected function recordSpend(?\Laravel\Ai\Responses\Data\Usage $usage): void
    {
        try {
            $this->ledger->record(self::FEATURE, trim($this->settings->chat_model), $usage, $this->ledger->captureContextCosts());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Deliver the assistant's reply within Telegram's 4096-char limit: a
     * single message edits the placeholder (MarkdownV2 with plain-text
     * fallback); a longer reply is chunked — the first chunk edits the
     * placeholder and the rest are sent as follow-up messages.
     */
    protected function sendReply(int $chatId, int $placeholderMessageId, string $text): void
    {
        if ($text === '') {
            $this->editMessage($chatId, $placeholderMessageId, 'لم يتم الحصول على رد. حاول مرة أخرى.');

            return;
        }

        $chunks = $this->chunkForTelegram($text);

        if (count($chunks) === 1) {
            $this->editWithMarkdownFallback($chatId, $placeholderMessageId, $chunks[0]);

            return;
        }

        $this->editMessage($chatId, $placeholderMessageId, array_shift($chunks));

        foreach ($chunks as $chunk) {
            try {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $chunk,
                ]);
            } catch (Throwable $exception) {
                Log::warning('Failed to send Telegram AI reply chunk', ['error' => $exception->getMessage()]);
            }
        }
    }

    /**
     * Split a long reply into <=4096-char chunks, preferring newline then
     * space boundaries so sentences stay whole.
     *
     * @return array<int, string>
     */
    protected function chunkForTelegram(string $text): array
    {
        $limit = self::TELEGRAM_MESSAGE_LIMIT;
        $chunks = [];

        while (mb_strlen($text) > $limit) {
            $slice = mb_substr($text, 0, $limit);

            $breakAt = mb_strrpos($slice, "\n");

            if ($breakAt === false || $breakAt < (int) ($limit * 0.6)) {
                $breakAt = mb_strrpos($slice, ' ');
            }

            if ($breakAt === false || $breakAt < (int) ($limit * 0.6)) {
                $breakAt = $limit;
            }

            $chunks[] = trim(mb_substr($text, 0, $breakAt));
            $text = trim(mb_substr($text, $breakAt));
        }

        if ($text !== '') {
            $chunks[] = $text;
        }

        return $chunks === [] ? [''] : $chunks;
    }

    /**
     * Edit the placeholder with the formatted reply: MarkdownV2 first, plain
     * text when Telegram rejects the formatting.
     */
    protected function editWithMarkdownFallback(int $chatId, int $messageId, string $text): void
    {
        try {
            $this->telegram->editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => (new TelegramMarkdownService)->toMarkdownV2($text),
                'parse_mode' => 'MarkdownV2',
            ]);

            return;
        } catch (Throwable) {
            // Fall through to plain text.
        }

        $this->editMessage($chatId, $messageId, $text);
    }

    protected function editMessage(int $chatId, int $messageId, string $text): void
    {
        try {
            $this->telegram->editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
            ]);
        } catch (Throwable $exception) {
            if (! str_contains($exception->getMessage(), 'message is not modified')) {
                Log::warning('Failed to edit Telegram AI message', ['error' => $exception->getMessage()]);
            }
        }
    }

    /**
     * The turn's extractable media, if any: the largest photo size, or a
     * document with a PDF/image mime.
     *
     * @return array{file_id: string, filename: string, mime: string}|null
     */
    protected function attachmentSource(Message $message): ?array
    {
        $photos = $message->getPhoto();

        if ($photos !== null && count($photos) > 0) {
            /** @var PhotoSize $largest */
            $largest = collect($photos)->last();

            return [
                'file_id' => (string) $largest->fileId,
                'filename' => 'photo.jpg',
                'mime' => 'image/jpeg',
            ];
        }

        $document = $message->getDocument();

        if ($document instanceof Document) {
            $mime = (string) $document->mimeType;

            if ($mime === 'application/pdf' || str_starts_with($mime, 'image/')) {
                return [
                    'file_id' => (string) $document->fileId,
                    'filename' => (string) ($document->fileName ?? 'document'),
                    'mime' => $mime,
                ];
            }
        }

        return null;
    }

    /**
     * Download the media via getFile and run it through the budget-gated
     * chat extractor. The temporary ChatAttachment row (owner-keyed to this
     * chat, never the public corpus) is deleted after the turn — deletion
     * also removes the stored file.
     *
     * @param  array{file_id: string, filename: string, mime: string}  $source
     */
    protected function extractAttachment(Message $message, int $chatId, array $source): ?ChatAttachment
    {
        $attachment = null;

        try {
            $path = ChatAttachment::DIRECTORY.'/telegram-'.uniqid().'-'.basename($source['filename']);

            $disk = Storage::disk(ChatAttachment::DISK);
            $disk->makeDirectory(ChatAttachment::DIRECTORY);

            $file = $this->telegram->getFile(['file_id' => $source['file_id']]);
            $this->telegram->downloadFile($file, $disk->path($path));

            $attachment = ChatAttachment::query()->create([
                'session_id' => 'telegram:'.$chatId,
                'original_filename' => $source['filename'],
                'disk' => ChatAttachment::DISK,
                'path' => $path,
                'mime' => $source['mime'],
                'size' => $disk->exists($path) ? $disk->size($path) : null,
                'status' => ChatAttachment::STATUS_EXTRACTING,
            ]);

            $attachment->update([
                'extracted_markdown' => $this->extractor->extract($attachment),
                'status' => ChatAttachment::STATUS_READY,
            ]);

            return $attachment;
        } catch (Throwable $exception) {
            Log::warning('Telegram AI attachment extraction failed', [
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);

            $attachment?->delete();

            return null;
        }
    }

    /**
     * A pending stateful flow (page creation, login, code input, …) owns the
     * user's next message; the assistant stays quiet.
     */
    protected function anotherHandlerAwaitsInput(int $userId): bool
    {
        foreach (self::STATEFUL_HANDLERS as $handlerClass) {
            if (Cache::has(self::USER_STATE_CACHE_PREFIX.$handlerClass.':'.$userId)) {
                return true;
            }
        }

        $flowStateKeys = [
            config('app-cache.keys.telegram_page_mgmt_state', 'telegram_page_mgmt_state_').$userId,
            config('app-cache.keys.telegram_login_state', 'telegram_login_state_').$userId,
        ];

        foreach ($flowStateKeys as $key) {
            if (Cache::has($key)) {
                return true;
            }
        }

        return false;
    }

    protected function isGroupChat(Message $message): bool
    {
        return in_array($message->getChat()->getType(), ['group', 'supergroup'], true);
    }
}
