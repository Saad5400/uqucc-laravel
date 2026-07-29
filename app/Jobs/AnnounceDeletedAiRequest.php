<?php

namespace App\Jobs;

use App\Services\Telegram\MessagePresenceProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Telegram\Bot\Api;
use Throwable;

/**
 * Keeps an answered «سيك …» ask honest: when the asker deletes their question
 * after the assistant answered it, the bot replies to its own answer with the
 * question's original text and a mention of who sent it — so a group never
 * ends up with a floating answer whose question has quietly disappeared.
 *
 * The Bot API reports no deletions, so the message is polled with the silent
 * {@see MessagePresenceProbe} on a widening schedule and the job re-queues
 * itself between probes. Anything short of a confirmed deletion (still there,
 * or an inconclusive probe) just waits for the next one; after the last probe
 * the watch ends and the ask is considered kept.
 */
class AnnounceDeletedAiRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Seconds to wait before each probe, counted from the previous one — so
     * the message is checked 30s, 2m, 10m, 30m, and 2h after the answer.
     *
     * @var array<int, int>
     */
    public const PROBE_DELAYS = [30, 90, 480, 1200, 5400];

    /** Longest quoted question kept, in characters, before truncation. */
    protected const TEXT_LIMIT = 3000;

    public function __construct(
        public int $chatId,
        public int $requestMessageId,
        public int $replyMessageId,
        public string $requestText,
        public int $senderId,
        public string $senderName,
        public ?string $senderUsername = null,
        public ?string $attachmentLabel = null,
        public int $probe = 0,
    ) {
        $this->onQueue('telegram');
    }

    /**
     * Start watching an answered ask: the first probe runs once the asker has
     * had a moment to delete.
     */
    public static function watch(
        int $chatId,
        int $requestMessageId,
        int $replyMessageId,
        string $requestText,
        int $senderId,
        string $senderName,
        ?string $senderUsername = null,
        ?string $attachmentLabel = null,
    ): void {
        self::dispatch(
            chatId: $chatId,
            requestMessageId: $requestMessageId,
            replyMessageId: $replyMessageId,
            requestText: $requestText,
            senderId: $senderId,
            senderName: $senderName,
            senderUsername: $senderUsername,
            attachmentLabel: $attachmentLabel,
        )->delay(now()->addSeconds(self::PROBE_DELAYS[0]));
    }

    public function handle(Api $telegram, MessagePresenceProbe $probe): void
    {
        if ($probe->isDeleted($this->chatId, $this->requestMessageId) === true) {
            $this->announce($telegram);

            return;
        }

        $this->probeAgainLater();
    }

    /**
     * Reply to the assistant's own answer with what was asked and by whom. A
     * failure here means the answer itself is gone too (nothing left to
     * annotate), so the watch simply ends.
     */
    protected function announce(Api $telegram): void
    {
        try {
            $telegram->sendMessage([
                'chat_id' => $this->chatId,
                'text' => $this->announcement(),
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $this->replyMessageId,
                'disable_web_page_preview' => true,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Failed to announce a deleted Telegram AI request', [
                'chat_id' => $this->chatId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The Arabic notice: who deleted, what they had asked, and a note about
     * any attachment the ask carried.
     */
    protected function announcement(): string
    {
        $lines = ['🗑 حذف '.$this->mention().' سؤاله بعد الإجابة عليه، وهذا نصه:'];

        $text = trim($this->requestText);

        if ($text !== '') {
            $lines[] = '<blockquote expandable>'.$this->escape(Str::limit($text, self::TEXT_LIMIT)).'</blockquote>';
        }

        if ($this->attachmentLabel !== null && trim($this->attachmentLabel) !== '') {
            $lines[] = 'وكان مرفقاً بالسؤال: '.$this->escape(trim($this->attachmentLabel));
        }

        return implode("\n", $lines);
    }

    /**
     * A real mention that notifies the asker: their display name as a
     * tg://user link, plus their @username when they have one.
     */
    protected function mention(): string
    {
        $name = trim($this->senderName);
        $username = trim((string) $this->senderUsername);

        $label = $name !== '' ? $name : ($username !== '' ? '@'.$username : 'المستخدم');

        $mention = '<a href="tg://user?id='.$this->senderId.'">'.$this->escape($label).'</a>';

        return $name !== '' && $username !== ''
            ? $mention.' (@'.$this->escape($username).')'
            : $mention;
    }

    protected function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Queue the next probe in the schedule, if any are left.
     */
    protected function probeAgainLater(): void
    {
        $next = $this->probe + 1;

        if (! isset(self::PROBE_DELAYS[$next])) {
            return;
        }

        self::dispatch(
            chatId: $this->chatId,
            requestMessageId: $this->requestMessageId,
            replyMessageId: $this->replyMessageId,
            requestText: $this->requestText,
            senderId: $this->senderId,
            senderName: $this->senderName,
            senderUsername: $this->senderUsername,
            attachmentLabel: $this->attachmentLabel,
            probe: $next,
        )->delay(now()->addSeconds(self::PROBE_DELAYS[$next]));
    }
}
