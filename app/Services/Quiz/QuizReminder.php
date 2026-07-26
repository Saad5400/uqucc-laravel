<?php

namespace App\Services\Quiz;

use App\Helpers\ArabicPlural;
use App\Models\DailyQuiz;
use App\Models\QuizPost;
use App\Settings\QuizSettings;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

/**
 * The "answer the question of the day" nudges. While a quiz is live, the bot
 * gently re-floats it by replying to the poll message (so a single tap reaches
 * the poll) rather than posting a fresh block — the least-annoying way to fight
 * the message getting buried in an active group.
 *
 * Six phases spread across the quiz's 24-hour window (see the schedule in
 * routes/console.php), each with its own voice so the day does not read as the
 * same nudge six times:
 *   - {@see self::OPENER}: the question just went up.
 *   - {@see self::REFLOAT}: taunts with the share of answers that were wrong.
 *   - {@see self::NIGHT}: a last pass before the group goes to sleep.
 *   - {@see self::MORNING}: the morning re-float, carrying accuracy so far.
 *   - {@see self::HINT}: the question's stored non-spoiler hint.
 *   - {@see self::LASTCALL}: "closes soon", carrying the blunter second hint.
 *
 * A phase whose line has nothing to say is skipped rather than softened: the
 * three turnout phases need at least one answer to quote, and the two hint
 * phases need a stored hint.
 */
class QuizReminder
{
    public const OPENER = 'opener';

    public const REFLOAT = 'refloat';

    public const NIGHT = 'night';

    public const MORNING = 'morning';

    public const HINT = 'hint';

    public const LASTCALL = 'lastcall';

    /** Every phase `quiz:remind` accepts, in the order they fire. */
    public const PHASES = [
        self::OPENER,
        self::REFLOAT,
        self::NIGHT,
        self::MORNING,
        self::HINT,
        self::LASTCALL,
    ];

    private ?Api $telegram;

    public function __construct(
        private readonly QuizSettings $settings,
        ?Api $telegram = null,
    ) {
        $this->telegram = $telegram;
    }

    /**
     * Send the given phase's reminder for every currently live quiz (one per
     * group it was posted to). No-op while the feature or reminders are off.
     */
    public function remind(string $phase): void
    {
        if (! $this->settings->isConfigured() || ! $this->settings->reminders_enabled) {
            return;
        }

        $openPosts = QuizPost::query()
            ->open()
            ->with('quiz')
            ->get()
            ->filter(fn (QuizPost $post): bool => $post->quiz !== null);

        foreach ($openPosts->groupBy('daily_quiz_id') as $posts) {
            $quiz = $posts->first()->quiz;
            $text = $this->text($phase, $quiz);

            if ($text === null) {
                continue;
            }

            foreach ($posts as $post) {
                $this->replyToPoll($post, $text);
            }
        }
    }

    /**
     * Reply to the poll message so the reminder re-surfaces the poll itself.
     * Best-effort: if the poll message is gone (deleted, or the bot was
     * removed), the send just fails and is logged.
     */
    private function replyToPoll(QuizPost $post, string $text): void
    {
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
            Log::warning('Failed to send quiz reminder', [
                'quiz_post_id' => $post->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * This phase's reminder body, or null when the phase has nothing to say
     * for this quiz yet — no answers to quote, or no hint stored.
     */
    private function text(string $phase, DailyQuiz $quiz): ?string
    {
        return match ($phase) {
            self::OPENER => $this->withParticipants($quiz, fn (int $participants): string => 'سؤال اليوم نازل، وجاوب عليه '.ArabicPlural::people($participants).' — وأنت؟'),
            self::REFLOAT => $this->withParticipants($quiz, fn (int $participants): string => 'سؤال اليوم غلطوا فيه '.$this->percentOf($quiz, false, $participants).'%، بتقدر عليه؟'),
            self::NIGHT => 'قبل ما تنام 🌙 سؤال اليوم لسه مفتوح',
            self::MORNING => $this->withParticipants($quiz, fn (int $participants): string => 'صباح الخير ☕ نسبة الإجابات الصحيحة في سؤال اليوم '.$this->percentOf($quiz, true, $participants).'% — تقدر ترفعها؟'),
            self::HINT => filled($quiz->hint) ? 'تلميح لسؤال اليوم: '.$this->escape($quiz->hint) : null,
            self::LASTCALL => $this->lastCall($quiz),
            default => null,
        };
    }

    /**
     * Build a turnout-quoting line, or null while nobody has answered — with
     * no participants there is no share to quote.
     *
     * @param  callable(int): string  $line
     */
    private function withParticipants(DailyQuiz $quiz, callable $line): ?string
    {
        $participants = $quiz->answers()->count();

        return $participants === 0 ? null : $line($participants);
    }

    /**
     * The share of answers that were correct (or wrong), as a whole percent.
     */
    private function percentOf(DailyQuiz $quiz, bool $correct, int $participants): int
    {
        $matching = $quiz->answers()->where('is_correct', $correct)->count();

        return (int) round($matching / $participants * 100);
    }

    /**
     * The final nudge, carrying the blunter second hint. Questions authored
     * before the second hint existed fall back to the subtle one, and a quiz
     * with neither gets the bare "last chance" line.
     */
    private function lastCall(DailyQuiz $quiz): string
    {
        $line = 'آخر فرصة في سؤال اليوم';
        $hint = filled($quiz->obvious_hint) ? $quiz->obvious_hint : $quiz->hint;

        if (filled($hint)) {
            $line .= '، تلميح: '.$this->escape($hint);
        }

        return $line;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function telegram(): Api
    {
        return $this->telegram ??= new Api((string) config('services.telegram.token'), false);
    }
}
