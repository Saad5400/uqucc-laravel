<?php

namespace App\Services\Quiz;

use App\Helpers\ArabicPlural;
use App\Models\DailyQuiz;
use App\Models\QuizPlayer;
use App\Models\QuizPost;
use App\Services\TelegramMarkdownService;
use App\Settings\QuizSettings;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Telegram\Bot\Api;

/**
 * Everything the bot sends to the groups for the daily quiz: one quiz poll
 * per configured group (non-anonymous quiz polls, so votes arrive as
 * attributable `poll_answer` updates that map back to one shared quiz) and
 * the weekly winners announcement.
 *
 * The Api client is built lazily so the service can be container-resolved in
 * environments without a bot token; tests pass a FakeTelegramApi instead.
 */
class QuizPoster
{
    /** How many players the weekly winners announcement names. */
    public const WEEKLY_WINNERS = 20;

    /**
     * The poll question used when the quiz carries a {@see DailyQuiz::$body} —
     * the real wording and any code live in the formatted message sent just
     * above the poll, so the poll itself only points the reader up to it. Poll
     * questions are plain text with no monospace, which is exactly why code
     * cannot live here.
     */
    public const POLL_LEAD_IN = '👆 السؤال في الرسالة أعلاه — اختر إجابتك:';

    private ?Api $telegram;

    public function __construct(
        private readonly QuizSettings $settings,
        ?Api $telegram = null,
    ) {
        $this->telegram = $telegram;
    }

    /**
     * Post the quiz to every configured group. Stops the previous day's
     * polls first — one live quiz at a time keeps late votes from outliving
     * the day they belong to. A group that fails (bot kicked, no rights) is
     * logged and skipped; the quiz counts as posted while at least one group
     * got it.
     */
    public function post(DailyQuiz $quiz): DailyQuiz
    {
        if (! $this->settings->isConfigured()) {
            throw new RuntimeException('سؤال اليوم غير مهيأ — فعّله وحدد المجموعات من صفحة سؤال اليوم.');
        }

        if (! $quiz->isReady()) {
            throw new RuntimeException('هذا السؤال ليس بانتظار النشر.');
        }

        $this->closeOpenQuizzes();

        $content = $this->contentHtml($quiz);

        $params = [
            'question' => $this->pollQuestion($quiz),
            'options' => array_values($quiz->options),
            'type' => 'quiz',
            'is_anonymous' => false,
            'correct_option_id' => $quiz->correct_option,
        ];

        if (filled($quiz->explanation)) {
            $params['explanation'] = $quiz->explanation;
        }

        foreach ($this->settings->targets() as $target) {
            try {
                if ($content !== null) {
                    $this->telegram()->sendMessage($target->apply([
                        'text' => $content,
                        'parse_mode' => 'HTML',
                        'disable_web_page_preview' => true,
                    ]));
                }

                $message = $this->telegram()->sendPoll($target->apply($params));

                $post = QuizPost::create([
                    'daily_quiz_id' => $quiz->id,
                    'chat_id' => $message->getChat()->getId(),
                    'message_id' => $message->getMessageId(),
                    'message_thread_id' => $target->threadId,
                    'telegram_poll_id' => $message->getPoll()?->getId(),
                    'posted_at' => now(),
                ]);

                $this->pinQuietly($post);
            } catch (\Throwable $exception) {
                Log::error('Failed to post quiz to chat', [
                    'quiz_id' => $quiz->id,
                    'chat_id' => $target->chatId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($quiz->posts()->doesntExist()) {
            throw new RuntimeException('تعذّر نشر السؤال في أي من المجموعات المحددة.');
        }

        $quiz->update([
            'status' => DailyQuiz::STATUS_POSTED,
            'posted_at' => now(),
        ]);

        return $quiz->refresh();
    }

    /**
     * The poll's question line. When the quiz has a {@see DailyQuiz::$body}
     * (code or a scenario that must render as formatted text), the wording
     * lives in the message posted above the poll and the poll only carries a
     * generic lead-in; otherwise the question itself is the poll question.
     */
    private function pollQuestion(DailyQuiz $quiz): string
    {
        return filled($quiz->body) ? self::POLL_LEAD_IN : $quiz->question;
    }

    /**
     * The formatted HTML message shown just above the poll, or null when the
     * quiz needs none. The body's markdown (fenced code included) and the
     * question are rendered through the same converter the «سيك» assistant
     * uses — so code becomes a real monospace <pre> block, sidestepping the
     * bidi mangling a plain-text poll question suffers — but deliberately
     * without the expandable-blockquote wrapper, so the question reads openly.
     */
    private function contentHtml(DailyQuiz $quiz): ?string
    {
        if (! filled($quiz->body)) {
            return null;
        }

        $markdown = trim((string) $quiz->body)."\n\n".trim($quiz->question);

        return (new TelegramMarkdownService)->toTelegramHtml($markdown);
    }

    /**
     * Pin the quiz for the day without notifying the members (the poll
     * itself is the announcement). Best-effort: the bot may lack the "pin
     * messages" admin right, and the quiz works fine unpinned.
     */
    private function pinQuietly(QuizPost $post): void
    {
        try {
            $this->telegram()->pinChatMessage([
                'chat_id' => $post->chat_id,
                'message_id' => $post->message_id,
                'disable_notification' => true,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to pin quiz message', [
                'quiz_post_id' => $post->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Stop every still-open quiz poll (normally yesterday's, one per group).
     * A poll Telegram already closed just makes stopPoll throw; the post is
     * marked closed regardless so scoring stops accepting its votes.
     */
    public function closeOpenQuizzes(): void
    {
        $openPosts = QuizPost::query()->open()->with('quiz')->get();

        foreach ($openPosts as $post) {
            try {
                $this->telegram()->stopPoll([
                    'chat_id' => $post->chat_id,
                    'message_id' => $post->message_id,
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Failed to stop previous quiz poll', [
                    'quiz_post_id' => $post->id,
                    'message' => $exception->getMessage(),
                ]);
            }

            $this->sendRecap($post);
            $this->unpinQuietly($post);

            $post->update(['closed_at' => now()]);
        }

        DailyQuiz::query()
            ->where('status', DailyQuiz::STATUS_POSTED)
            ->get()
            ->each(fn (DailyQuiz $quiz) => $quiz->update([
                'status' => DailyQuiz::STATUS_CLOSED,
                'closed_at' => now(),
            ]));
    }

    /**
     * Unpin exactly this post's message — passing message_id makes Telegram
     * leave every other pinned message in the group untouched. Best-effort,
     * like the pin.
     */
    private function unpinQuietly(QuizPost $post): void
    {
        try {
            $this->telegram()->unpinChatMessage([
                'chat_id' => $post->chat_id,
                'message_id' => $post->message_id,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Failed to unpin quiz message', [
                'quiz_post_id' => $post->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Reply to a just-closed poll with how the day went — turnout, accuracy
     * and the longest streak in play — so the daily ritual leaves a visible
     * trace. Best-effort and skipped entirely when nobody answered (an empty
     * recap is just noise).
     */
    private function sendRecap(QuizPost $post): void
    {
        $quiz = $post->quiz;

        if ($quiz === null) {
            return;
        }

        $text = $this->recapText($quiz);

        if ($text === null) {
            return;
        }

        try {
            $params = [
                'chat_id' => $post->chat_id,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_to_message_id' => $post->message_id,
            ];

            if ($post->message_thread_id !== null) {
                $params['message_thread_id'] = $post->message_thread_id;
            }

            $this->telegram()->sendMessage($params);
        } catch (\Throwable $exception) {
            Log::warning('Failed to send quiz recap', [
                'quiz_post_id' => $post->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The recap body for a finished quiz, or null when it had no answers.
     */
    private function recapText(DailyQuiz $quiz): ?string
    {
        $total = $quiz->answers()->count();

        if ($total === 0) {
            return null;
        }

        $correct = $quiz->answers()->where('is_correct', true)->count();
        $percent = (int) round($correct / $total * 100);

        $lines = [
            '📊 <b>خلاصة سؤال اليوم</b>',
            '🧑‍🎓 شارك: '.ArabicPlural::people($total),
            '✅ إجابات صحيحة: '.$correct.' من '.$total.' ('.$percent.'٪)',
        ];

        $topStreak = $quiz->answers()
            ->with('player')
            ->orderByDesc('streak_at_answer')
            ->first();

        if ($topStreak !== null && $topStreak->streak_at_answer > 1 && $topStreak->player !== null) {
            $lines[] = '🔥 أطول سلسلة: '.htmlspecialchars($topStreak->player->displayName(), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                .' — '.ArabicPlural::days($topStreak->streak_at_answer);
        }

        return implode("\n", $lines);
    }

    /**
     * Announce this week's top players in every configured group, then start
     * the new week by resetting every player's weekly points. Quietly does
     * nothing when nobody scored — an empty podium is worse than no message.
     */
    public function announceWeeklyWinners(): void
    {
        if (! $this->settings->isConfigured()) {
            return;
        }

        $winners = QuizPlayer::query()
            ->where('weekly_points', '>', 0)
            ->orderByDesc('weekly_points')
            ->orderByDesc('current_streak')
            ->orderBy('id')
            ->limit(self::WEEKLY_WINNERS)
            ->get();

        if ($winners->isEmpty()) {
            return;
        }

        $medals = ['🥇', '🥈', '🥉'];

        $lines = $winners
            ->values()
            ->map(fn (QuizPlayer $player, int $index): string => sprintf(
                '%s %s — %s',
                $medals[$index] ?? ($index + 1).'.',
                htmlspecialchars($player->displayName(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ArabicPlural::points($player->weekly_points),
            ))
            ->implode("\n");

        $text = "🏆 <b>متصدرو سؤال اليوم هذا الأسبوع</b>\n\n{$lines}\n\nبدأ أسبوع جديد — عدّادات الأسبوع صُفّرت، والفرصة مفتوحة للجميع. لا تفوّتوا سؤال الغد! 👀";

        foreach ($this->settings->targets() as $target) {
            try {
                $this->telegram()->sendMessage($target->apply([
                    'text' => $text,
                    'parse_mode' => 'HTML',
                ]));
            } catch (\Throwable $exception) {
                Log::warning('Failed to announce weekly winners in chat', [
                    'chat_id' => $target->chatId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        QuizPlayer::query()->where('weekly_points', '>', 0)->update(['weekly_points' => 0]);
    }

    private function telegram(): Api
    {
        return $this->telegram ??= new Api((string) config('services.telegram.token'), false);
    }
}
