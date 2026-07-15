<?php

namespace App\Services\Telegram\Handlers;

use App\Ai\Agents\StudentAssistant;
use App\Ai\Chat\AttachmentContext;
use App\Ai\Chat\ChatAttachmentTextExtractor;
use App\Ai\Chat\SessionOwner;
use App\Ai\Spend\SpendLedger;
use App\Models\Ai\ChatAttachment;
use App\Models\TelegramChatSetting;
use App\Services\Telegram\TelegramStreamingProgress;
use App\Services\TelegramMarkdownService;
use App\Settings\AiSettings;
use App\Support\LocalFile;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Streaming\Events\Error as ErrorEvent;
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
 * the explicit «سيك …» ask prefix on the message text/caption (the ONLY
 * invocation, in every chat type — the bot never answers plain chatter,
 * replies, or mentions) → per-CHAT rate limits (burst + operator daily
 * quota) → the daily spend budget via the {@see SpendLedger}.
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

    /**
     * The explicit ask prefix: «سيك سؤالك…», with the legacy DeepSeekChatHandler
     * forms «اسال سيك …» / «اسأل سيك …» still honoured.
     */
    protected const ASK_PATTERN = '/^(?:(?:اسال|اسأل)\s+)?سيك\s+(.+)$/us';

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
     * The user's ask for this turn: the message text (or the media caption)
     * when — and only when — it starts with the «سيك» ask prefix, which is
     * stripped. Anything else (plain chatter, /commands, other handlers'
     * commands, replies, mentions) is null: not an AI turn.
     */
    protected function promptFrom(Message $message): ?string
    {
        $raw = $message->getText() ?? $message->getCaption();
        $content = is_string($raw) ? trim($raw) : '';

        if ($content === '' || str_starts_with($content, '/')) {
            return null;
        }

        if (preg_match(self::ASK_PATTERN, $content, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
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
     * Run the turn against the shared assistant as a live stream, continuing
     * the chat's stored conversation. While the model works, the placeholder
     * message is edited with throttled plain-text progress (reasoning tail,
     * tool activity, the growing answer); the final formatted reply then
     * replaces it. Spend is captured from the gateway's Context costs exactly
     * like the web chat.
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
            $response = $agent->stream($prompt);

            $progress = new TelegramStreamingProgress($this->telegram, $chatId, $placeholderMessageId);

            $failed = false;

            foreach ($response as $event) {
                if ($event instanceof ErrorEvent) {
                    $failed = true;

                    continue;
                }

                $progress->note($event);
            }

            $this->recordSpend($response->usage ?? null);

            if ($failed) {
                $this->editMessage($chatId, $placeholderMessageId, 'حدث خطأ أثناء توليد الرد. حاول مرة أخرى.');

                return;
            }

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
     * Deliver the assistant's reply within Telegram's 4096-char limit. The
     * markdown is converted to Telegram HTML once, then chunked: the first
     * chunk edits the placeholder and the rest are sent as follow-up
     * messages, each falling back to plain text if Telegram rejects the
     * formatting.
     */
    protected function sendReply(int $chatId, int $placeholderMessageId, string $text): void
    {
        if ($text === '') {
            $this->editMessage($chatId, $placeholderMessageId, 'لم يتم الحصول على رد. حاول مرة أخرى.');

            return;
        }

        $formatter = new TelegramMarkdownService;
        $chunks = $this->chunkForTelegram($formatter->toTelegramHtml($this->withDisclaimer($text)));

        $this->editWithHtmlFallback($chatId, $placeholderMessageId, array_shift($chunks), $formatter);

        foreach ($chunks as $chunk) {
            $this->sendWithHtmlFallback($chatId, $chunk, $formatter);
        }
    }

    /**
     * Append the shared "AI-generated, may be wrong" disclaimer as a trailing
     * italic line. Appended in code (never model-generated) so it is always
     * present, and — because it is added before chunking — it lands at the end
     * of the final message chunk.
     */
    protected function withDisclaimer(string $text): string
    {
        $disclaimer = trim((string) config('ai.assistant.disclaimer'));

        return $disclaimer === '' ? $text : $text."\n\n_".$disclaimer.'_';
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
     * Edit the placeholder with the formatted reply: HTML first, the same
     * text stripped to plain when Telegram rejects the formatting.
     */
    protected function editWithHtmlFallback(int $chatId, int $messageId, string $html, TelegramMarkdownService $formatter): void
    {
        try {
            $this->telegram->editMessageText([
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            return;
        } catch (Throwable) {
            // Fall through to plain text.
        }

        $this->editMessage($chatId, $messageId, $formatter->toPlainText($html));
    }

    protected function sendWithHtmlFallback(int $chatId, string $html, TelegramMarkdownService $formatter): void
    {
        try {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            return;
        } catch (Throwable) {
            // Fall through to plain text.
        }

        try {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $formatter->toPlainText($html),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Failed to send Telegram AI reply chunk', ['error' => $exception->getMessage()]);
        }
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
            $filename = 'telegram-'.uniqid().'-'.basename($source['filename']);
            $path = ChatAttachment::DIRECTORY.'/'.$filename;

            $disk = Storage::disk(ChatAttachment::DISK);

            $file = $this->telegram->getFile(['file_id' => $source['file_id']]);

            $download = LocalFile::temporary(pathinfo($source['filename'], PATHINFO_EXTENSION));
            $this->telegram->downloadFile($file, $download->path);
            $disk->putFileAs(ChatAttachment::DIRECTORY, new File($download->path), $filename);

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
}
