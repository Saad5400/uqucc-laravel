<?php

namespace App\Jobs;

use App\Ai\Chat\AttachmentContext;
use App\Ai\Chat\ChatAttachmentTextExtractor;
use App\Ai\Chat\TelegramTurnContext;
use App\Ai\Spend\SpendLedger;
use App\Services\Logic\TruthTableGenerator;
use App\Services\Logic\TruthTableImageRenderer;
use App\Services\OgImageService;
use App\Services\QuickResponseService;
use App\Services\Telegram\ContentParser;
use App\Services\Telegram\Handlers\AiChatHandler;
use App\Services\Telegram\Handlers\AiToggleHandler;
use App\Services\Telegram\Handlers\EditLinkHandler;
use App\Services\Telegram\Handlers\ExternalSearchHandler;
use App\Services\Telegram\Handlers\HelpHandler;
use App\Services\Telegram\Handlers\InfoHandler;
use App\Services\Telegram\Handlers\InviteLinkHandler;
use App\Services\Telegram\Handlers\JavaExecutionHandler;
use App\Services\Telegram\Handlers\LoginHandler;
use App\Services\Telegram\Handlers\PageManagementHandler;
use App\Services\Telegram\Handlers\PrivateForwardHandler;
use App\Services\Telegram\Handlers\PythonExecutionHandler;
use App\Services\Telegram\Handlers\TruthTableHandler;
use App\Services\Telegram\Handlers\UquccListHandler;
use App\Services\Telegram\Handlers\UquccSearchHandler;
use App\Services\TipTapContentExtractor;
use App\Settings\AiSettings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Update;

class ProcessTelegramUpdate implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     *
     * @param  array<string, mixed>  $updateData  The update data as an array
     */
    public function __construct(
        public array $updateData
    ) {
        // Use dedicated queue for telegram updates to enable concurrent processing
        $this->onQueue('telegram');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $telegram = $this->makeTelegram();
            $update = new Update($this->updateData);

            $this->processUpdate($telegram, $update);
        } catch (\Exception $e) {
            Log::error('ProcessTelegramUpdate job failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
                'update_id' => $this->updateData['update_id'] ?? 'unknown',
            ]);
        }
    }

    /**
     * Build the Telegram API client used to answer this update.
     */
    protected function makeTelegram(): Api
    {
        return new Api(config('services.telegram.token'), false);
    }

    /**
     * How old (in seconds) the reply target must be to be flagged as suspicious.
     */
    protected const OLD_REPLY_THRESHOLD_SECONDS = 1800; // 30 minutes

    /**
     * TEMPORARY diagnostic. Determine which message the bot would attach its
     * reply to (the quoted original for a reply, otherwise the message itself),
     * and if that target was sent more than 30 minutes ago, dump the complete
     * update plus the computed signals to the `telegram_old_reply` channel.
     *
     * This is what pins down why the bot replies to month-old messages: it tells
     * us whether the trigger was a normal reply to an old message, an edited old
     * message, a channel post, and exactly what text/chat/sender caused it.
     */
    protected function logOldReplyTarget(Update $update, \Telegram\Bot\Objects\Message $message): void
    {
        try {
            $replyTo = $message->getReplyToMessage();
            $isReply = $replyTo instanceof \Telegram\Bot\Objects\Message;

            // Mirror BaseHandler/UquccSearchHandler: reply to the quoted original
            // when present, otherwise to the incoming message itself.
            $target = $isReply ? $replyTo : $message;
            $targetDate = (int) $target->getDate();

            if ($targetDate <= 0) {
                return;
            }

            $ageSeconds = now()->getTimestamp() - $targetDate;

            if ($ageSeconds < self::OLD_REPLY_THRESHOLD_SECONDS) {
                return;
            }

            $updateType = collect(['message', 'edited_message', 'channel_post', 'edited_channel_post'])
                ->first(fn (string $key): bool => isset($this->updateData[$key])) ?? 'unknown';

            $text = $message->getText() ?? $message->getCaption();

            Log::channel('telegram_old_reply')->warning('Bot processed an update targeting an old message', [
                'update_id' => $this->updateData['update_id'] ?? null,
                'update_type' => $updateType,
                'reply_target_age_seconds' => $ageSeconds,
                'reply_target_age_human' => now()->setTimestamp($targetDate)->diffForHumans(),
                'reply_target_message_id' => $target->getMessageId(),
                'reply_target_sent_at' => now()->setTimestamp($targetDate)->toIso8601String(),
                'is_reply_to_older_message' => $isReply,
                'incoming' => [
                    'message_id' => $message->getMessageId(),
                    'sent_at' => $targetDate === (int) $message->getDate()
                        ? null
                        : now()->setTimestamp((int) $message->getDate())->toIso8601String(),
                    'edited_at' => $message->getEditDate()
                        ? now()->setTimestamp((int) $message->getEditDate())->toIso8601String()
                        : null,
                    'text' => is_string($text) ? $text : null,
                ],
                'chat' => [
                    'id' => $message->getChat()?->getId(),
                    'type' => $message->getChat()?->getType(),
                    'title' => $message->getChat()?->getTitle(),
                    'username' => $message->getChat()?->getUsername(),
                ],
                'from' => [
                    'id' => $message->getFrom()?->getId(),
                    'is_bot' => $message->getFrom()?->getIsBot(),
                    'username' => $message->getFrom()?->getUsername(),
                    'first_name' => $message->getFrom()?->getFirstName(),
                ],
                'reply_to' => $isReply ? [
                    'message_id' => $replyTo->getMessageId(),
                    'from_id' => $replyTo->getFrom()?->getId(),
                    'from_first_name' => $replyTo->getFrom()?->getFirstName(),
                    'text' => is_string($replyTo->getText()) ? $replyTo->getText() : $replyTo->getCaption(),
                ] : null,
                'raw_update' => $this->updateData,
            ]);
        } catch (\Throwable $e) {
            Log::error('logOldReplyTarget failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);
        }
    }

    /**
     * Process a single Telegram update.
     */
    protected function processUpdate(Api $telegram, Update $update): void
    {
        // Handle callback queries (inline button presses)
        $callbackQuery = $update->getCallbackQuery();
        if ($callbackQuery) {
            try {
                $pageManagementHandler = new PageManagementHandler($telegram, app(ContentParser::class));
                $pageManagementHandler->handleCallback($callbackQuery);
            } catch (\Exception $e) {
                Log::error('Telegram callback error', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile().':'.$e->getLine(),
                ]);
            }

            return;
        }

        $message = $update->getMessage();

        if (! $message instanceof \Telegram\Bot\Objects\Message) {
            return;
        }

        // TEMPORARY diagnostic: record any update whose reply target is an old
        // message. Placed before the edit guard on purpose so edits are captured
        // too. Remove together with the `telegram_old_reply` log channel.
        $this->logOldReplyTarget($update, $message);

        // Ignore message edits. An `edited_message` update carries the original
        // message's id and date, and its object extends Message — so without this
        // guard, editing a month-old message re-runs every handler and makes the
        // bot reply to that old message as if it were brand new.
        if (isset($this->updateData['edited_message']) || isset($this->updateData['edited_channel_post'])) {
            return;
        }

        if ($message->getFrom()?->getIsBot()) {
            return;
        }

        // Initialize handlers
        $handlers = [
            new HelpHandler($telegram),
            new LoginHandler($telegram),
            new PageManagementHandler($telegram, app(ContentParser::class)),
            new EditLinkHandler($telegram),
            new ExternalSearchHandler($telegram), // Priority handler for قوقل and قيم commands
            new UquccSearchHandler($telegram, app(QuickResponseService::class), app(TipTapContentExtractor::class), app(OgImageService::class)),
            new UquccListHandler($telegram),
            new PythonExecutionHandler($telegram),
            new JavaExecutionHandler($telegram),
            new TruthTableHandler($telegram, app(TruthTableGenerator::class), app(TruthTableImageRenderer::class)),
            new InfoHandler($telegram),
            new PrivateForwardHandler($telegram),
            new InviteLinkHandler($telegram),
            new AiToggleHandler($telegram),
            // Last on purpose: the assistant only answers messages no other handler owns.
            new AiChatHandler(
                $telegram,
                app(AiSettings::class),
                app(SpendLedger::class),
                app(ChatAttachmentTextExtractor::class),
                app(AttachmentContext::class),
                app(TelegramTurnContext::class),
            ),
        ];

        foreach ($handlers as $handler) {
            try {
                $handler->handle($message);
            } catch (\Exception $e) {
                Log::error('Telegram handler error', [
                    'handler' => get_class($handler),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile().':'.$e->getLine(),
                ]);
            }
        }
    }
}
