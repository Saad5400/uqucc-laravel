<?php

namespace App\Services\Quiz;

use App\Helpers\ArabicPlural;
use App\Models\DailyQuiz;
use App\Models\QuizPlayer;
use App\Models\QuizPost;
use App\Settings\QuizSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;

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
     * The generic prompt on the poll itself — the question and its options live
     * in the image above it, so the poll only needs to send the reader there
     * and collect a numbered vote.
     */
    public const POLL_QUESTION = 'اختر رقم الإجابة الصحيحة من الصورة بالأعلى ⬆️';

    /** The poll's four choices: bare numbers matching the image's labels. */
    private const POLL_OPTIONS = ['1', '2', '3', '4'];

    private ?Api $telegram;

    private ?QuizImageRenderer $imageRenderer;

    public function __construct(
        private readonly QuizSettings $settings,
        ?Api $telegram = null,
        ?QuizImageRenderer $imageRenderer = null,
    ) {
        $this->telegram = $telegram;
        $this->imageRenderer = $imageRenderer;
    }

    /**
     * Post the quiz to every configured group. Stops the previous day's
     * polls first — one live quiz at a time keeps late votes from outliving
     * the day they belong to. A group that fails (bot kicked, no rights) is
     * logged and skipped; the quiz counts as posted while at least one group
     * got it.
     *
     * `$force` re-posts a question that already went out — the escape hatch
     * for a poll that was deleted from the group by mistake. The quiz keeps
     * its identity, so the answers already recorded on it stay valid and
     * nobody can score twice; its own earlier polls are stopped quietly,
     * without the end-of-day recap, because the day is not over.
     */
    public function post(DailyQuiz $quiz, bool $force = false): DailyQuiz
    {
        if (! $this->settings->isConfigured()) {
            throw new RuntimeException('سؤال اليوم غير مهيأ — فعّله وحدد المجموعات من صفحة سؤال اليوم.');
        }

        if (! $quiz->isReady() && ! ($force && $quiz->isPosted())) {
            throw new RuntimeException('هذا السؤال ليس بانتظار النشر.');
        }

        $this->closeOpenQuizzes($quiz);
        $this->retireOwnPosts($quiz);

        $image = $this->renderImage($quiz);

        $params = [
            'question' => self::POLL_QUESTION,
            'options' => self::POLL_OPTIONS,
            'type' => 'quiz',
            'is_anonymous' => false,
            'correct_option_id' => $quiz->correct_option,
        ];

        if (filled($quiz->explanation)) {
            $params['explanation'] = $quiz->explanation;
        }

        $delivered = 0;

        foreach ($this->settings->targets() as $target) {
            try {
                $this->telegram()->sendPhoto($target->apply([
                    'photo' => InputFile::createFromContents($image, 'quiz.png'),
                ]));

                $message = $this->telegram()->sendPoll($target->apply($params));

                // One post row per quiz per group (a unique index enforces
                // it), so a re-post moves the row onto the new poll instead of
                // adding a second one that would silently fail to save.
                QuizPost::updateOrCreate([
                    'daily_quiz_id' => $quiz->id,
                    'chat_id' => $message->getChat()->getId(),
                ], [
                    'message_id' => $message->getMessageId(),
                    'message_thread_id' => $target->threadId,
                    'telegram_poll_id' => $message->getPoll()?->getId(),
                    'posted_at' => now(),
                    'closed_at' => null,
                ]);

                $delivered++;
            } catch (\Throwable $exception) {
                Log::error('Failed to post quiz to chat', [
                    'quiz_id' => $quiz->id,
                    'chat_id' => $target->chatId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($delivered === 0) {
            throw new RuntimeException('تعذّر نشر السؤال في أي من المجموعات المحددة.');
        }

        $quiz->update([
            'status' => DailyQuiz::STATUS_POSTED,
            'posted_at' => now(),
        ]);

        return $quiz->refresh();
    }

    /**
     * The question card as PNG bytes — the whole question, its scenario/code
     * and its four numbered options, drawn with correct direction so the poll
     * below can stay a generic "choose 1–4". Rendered once and reused across
     * every group. A rendering failure aborts the whole post rather than
     * sending a poll with no question to read.
     */
    private function renderImage(DailyQuiz $quiz): string
    {
        try {
            return $this->imageRenderer()->render($quiz);
        } catch (\Throwable $exception) {
            Log::error('Failed to render quiz image', [
                'quiz_id' => $quiz->id,
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException('تعذّر توليد صورة السؤال.');
        }
    }

    private function imageRenderer(): QuizImageRenderer
    {
        return $this->imageRenderer ??= app(QuizImageRenderer::class);
    }

    /**
     * Stop every still-open quiz poll (normally yesterday's, one per group)
     * and mark its quiz closed. `$except` spares one quiz — the one being
     * re-posted, which is continuing rather than ending.
     */
    public function closeOpenQuizzes(?DailyQuiz $except = null): void
    {
        $openPosts = QuizPost::query()
            ->open()
            ->when($except !== null, fn (Builder $query) => $query->where('daily_quiz_id', '!=', $except->id))
            ->with('quiz')
            ->get();

        foreach ($openPosts as $post) {
            $this->retirePost($post, recap: true);
        }

        DailyQuiz::query()
            ->where('status', DailyQuiz::STATUS_POSTED)
            ->when($except !== null, fn (Builder $query) => $query->whereKeyNot($except->getKey()))
            ->get()
            ->each(fn (DailyQuiz $quiz) => $quiz->update([
                'status' => DailyQuiz::STATUS_CLOSED,
                'closed_at' => now(),
            ]));
    }

    /**
     * Stop the quiz's own polls from an earlier posting round, without a
     * recap — a re-post is not the end of the day, and the recap belongs to
     * the poll that actually closes it.
     */
    private function retireOwnPosts(DailyQuiz $quiz): void
    {
        foreach ($quiz->posts()->open()->get() as $post) {
            $this->retirePost($post, recap: false);
        }
    }

    /**
     * Take one live poll out of circulation: close it on Telegram, optionally
     * reply with the day's recap, and mark the post closed so scoring stops
     * accepting its votes. A poll Telegram already closed — or a message
     * someone deleted — just makes the call throw; the post is marked closed
     * regardless.
     */
    private function retirePost(QuizPost $post, bool $recap): void
    {
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

        if ($recap) {
            $this->sendRecap($post);
        }

        $post->update(['closed_at' => now()]);
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
