<?php

namespace App\Services\Quiz;

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
 * Two phases, each conditional so quiet days stay quiet:
 *   - {@see self::REFLOAT}: a mid-window nudge that only fires while turnout is
 *     still low ({@see self::REFLOAT_MAX_PARTICIPANTS}); popular questions are
 *     left alone.
 *   - {@see self::LASTCALL}: a "closes soon" nudge before the quiz ends, which
 *     also carries the question's stored non-spoiler hint when it has one.
 */
class QuizReminder
{
    public const REFLOAT = 'refloat';

    public const LASTCALL = 'lastcall';

    /** Above this many answers the re-float stays silent — the question is doing fine. */
    public const REFLOAT_MAX_PARTICIPANTS = 25;

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
            $participants = $quiz->answers()->count();

            if ($phase === self::REFLOAT && $participants >= self::REFLOAT_MAX_PARTICIPANTS) {
                continue;
            }

            $text = $this->text($phase, $quiz, $participants);

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
     * The reminder body. The re-float taunts with the share of answers that
     * were wrong so far (a difficulty flex, not a headcount); the last call is
     * a short "your final chance" line that carries the stored hint when there
     * is one.
     */
    private function text(string $phase, DailyQuiz $quiz, int $participants): string
    {
        if ($phase === self::LASTCALL) {
            $line = 'آخر فرصة في سؤال اليوم';

            if (filled($quiz->hint)) {
                $line .= '، تلميح: '.$this->escape($quiz->hint);
            }

            return $line;
        }

        if ($participants === 0) {
            return 'سؤال اليوم مطروح ولم يجرّبه أحد بعد — بتقدر عليه؟';
        }

        $wrong = $quiz->answers()->where('is_correct', false)->count();
        $percent = (int) round($wrong / $participants * 100);

        return "سؤال اليوم غلطوا فيه {$percent}%، بتقدر عليه؟";
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
