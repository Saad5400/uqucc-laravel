<?php

namespace App\Services\Quiz;

use App\Models\DailyQuiz;
use App\Models\QuizPlayer;
use App\Settings\QuizSettings;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Telegram\Bot\Api;

/**
 * Everything the bot sends to the group for the daily quiz: the quiz poll
 * itself (a non-anonymous Telegram quiz poll, so votes arrive as attributable
 * `poll_answer` updates) and the weekly winners announcement.
 *
 * The Api client is built lazily so the service can be container-resolved in
 * environments without a bot token; tests pass a FakeTelegramApi instead.
 */
class QuizPoster
{
    /** How many players the weekly winners announcement names. */
    public const WEEKLY_WINNERS = 3;

    private ?Api $telegram;

    public function __construct(
        private readonly QuizSettings $settings,
        ?Api $telegram = null,
    ) {
        $this->telegram = $telegram;
    }

    /**
     * Post the quiz to the configured group. Stops the previous day's poll
     * first — one live quiz at a time keeps late votes from outliving the
     * day they belong to.
     */
    public function post(DailyQuiz $quiz): DailyQuiz
    {
        if (! $this->settings->isConfigured()) {
            throw new RuntimeException('سؤال اليوم غير مهيأ — فعّله وحدد المجموعة من صفحة سؤال اليوم.');
        }

        if (! $quiz->isReady()) {
            throw new RuntimeException('هذا السؤال ليس بانتظار النشر.');
        }

        $this->closeOpenQuizzes();

        $params = [
            'chat_id' => (int) $this->settings->chat_id,
            'question' => $quiz->question,
            'options' => array_values($quiz->options),
            'type' => 'quiz',
            'is_anonymous' => false,
            'correct_option_id' => $quiz->correct_option,
        ];

        if (filled($quiz->explanation)) {
            $params['explanation'] = $quiz->explanation;
        }

        $message = $this->telegram()->sendPoll($params);

        $quiz->update([
            'status' => DailyQuiz::STATUS_POSTED,
            'telegram_poll_id' => $message->getPoll()?->getId(),
            'chat_id' => $message->getChat()->getId(),
            'message_id' => $message->getMessageId(),
            'posted_at' => now(),
        ]);

        return $quiz->refresh();
    }

    /**
     * Stop every still-open quiz poll (normally exactly one — yesterday's).
     * A poll Telegram already closed just makes stopPoll throw; the row is
     * marked closed regardless so scoring stops accepting its votes.
     */
    public function closeOpenQuizzes(): void
    {
        $openQuizzes = DailyQuiz::query()
            ->where('status', DailyQuiz::STATUS_POSTED)
            ->whereNotNull('telegram_poll_id')
            ->get();

        foreach ($openQuizzes as $quiz) {
            try {
                $this->telegram()->stopPoll([
                    'chat_id' => $quiz->chat_id,
                    'message_id' => $quiz->message_id,
                ]);
            } catch (\Throwable $exception) {
                Log::warning('Failed to stop previous quiz poll', [
                    'quiz_id' => $quiz->id,
                    'message' => $exception->getMessage(),
                ]);
            }

            $quiz->update([
                'status' => DailyQuiz::STATUS_CLOSED,
                'closed_at' => now(),
            ]);
        }
    }

    /**
     * Announce this week's top players in the group, then start the new week
     * by resetting every player's weekly points. Quietly does nothing when
     * nobody scored — an empty podium is worse than no message.
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
            ->map(fn (QuizPlayer $player, int $index): string => sprintf(
                '%s %s — %d نقطة',
                $medals[$index] ?? '🏅',
                htmlspecialchars($player->displayName(), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $player->weekly_points,
            ))
            ->implode("\n");

        $this->telegram()->sendMessage([
            'chat_id' => (int) $this->settings->chat_id,
            'text' => "🏆 <b>متصدرو سؤال اليوم هذا الأسبوع</b>\n\n{$lines}\n\nبدأ أسبوع جديد — عدّادات الأسبوع صُفّرت، والفرصة مفتوحة للجميع. لا تفوّتوا سؤال الغد! 👀",
            'parse_mode' => 'HTML',
        ]);

        QuizPlayer::query()->where('weekly_points', '>', 0)->update(['weekly_points' => 0]);
    }

    private function telegram(): Api
    {
        return $this->telegram ??= new Api((string) config('services.telegram.token'), false);
    }
}
