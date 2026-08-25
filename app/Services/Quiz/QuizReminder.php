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
 * Twelve phases spread across the quiz's 24-hour window (see the schedule in
 * routes/console.php), each with its own voice so the day does not read as the
 * same nudge twelve times:
 *   - {@see self::KICKOFF}: the poll just went up.
 *   - {@see self::OPENER}: quotes how many have answered so far.
 *   - {@see self::TOPIC}: teases the subject the question came from.
 *   - {@see self::REFLOAT}: taunts with the share of answers that were wrong.
 *   - {@see self::MOMENTUM}: quotes the answers that landed in the last hour.
 *   - {@see self::NIGHT}: a last pass before the group goes to sleep.
 *   - {@see self::LATENIGHT}: the small-hours pass, for whoever is still up.
 *   - {@see self::MORNING}: the morning re-float, carrying accuracy so far.
 *   - {@see self::TRAP}: warns off the wrong option the crowd is falling for.
 *   - {@see self::HINT}: the question's stored non-spoiler hint.
 *   - {@see self::LASTCALL}: "closes soon", carrying the blunter second hint.
 *   - {@see self::CLOSING}: the buzzer, minutes before the poll stops.
 *
 * Every phase always has something to send: a phase whose data is missing —
 * no answers yet, a quiet hour, no stored hint, no topic — falls back to a
 * plain line in the same voice rather than staying silent.
 *
 * The one thing that does hold a nudge back is the group itself: while members
 * are muted (a closed group — the bot is an admin and could still post, but
 * nobody could reply), the reminder is dropped for that chat.
 */
class QuizReminder
{
    public const KICKOFF = 'kickoff';

    public const OPENER = 'opener';

    public const TOPIC = 'topic';

    public const REFLOAT = 'refloat';

    public const MOMENTUM = 'momentum';

    public const NIGHT = 'night';

    public const LATENIGHT = 'latenight';

    public const MORNING = 'morning';

    public const TRAP = 'trap';

    public const HINT = 'hint';

    public const LASTCALL = 'lastcall';

    public const CLOSING = 'closing';

    /** Every phase `quiz:remind` accepts, in the order they fire. */
    public const PHASES = [
        self::KICKOFF,
        self::OPENER,
        self::TOPIC,
        self::REFLOAT,
        self::MOMENTUM,
        self::NIGHT,
        self::LATENIGHT,
        self::MORNING,
        self::TRAP,
        self::HINT,
        self::LASTCALL,
        self::CLOSING,
    ];

    private ?Api $telegram;

    /**
     * Whether members may post, per chat id — one getChat per chat per run.
     *
     * @var array<int, bool>
     */
    private array $membersCanPost = [];

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
            ->with('quiz.topic')
            ->get()
            ->filter(fn (QuizPost $post): bool => $post->quiz !== null);

        foreach ($openPosts->groupBy('daily_quiz_id') as $posts) {
            $text = $this->text($phase, $posts->first()->quiz);

            foreach ($posts as $post) {
                if (! $this->membersCanPost($post->chat_id)) {
                    continue;
                }

                $this->replyToPoll($post, $text);
            }
        }
    }

    /**
     * Whether the group's members are currently allowed to post. A closed
     * group gets no nudges: the bot is an admin and its reply would go
     * through, but nobody there could answer it.
     *
     * Fails open — a chat whose permissions cannot be read (a Telegram
     * hiccup, the bot removed) is treated as open, so one failed lookup does
     * not silence the day.
     */
    private function membersCanPost(int $chatId): bool
    {
        if (array_key_exists($chatId, $this->membersCanPost)) {
            return $this->membersCanPost[$chatId];
        }

        try {
            $permission = data_get($this->telegram()->getChat(['chat_id' => $chatId])->toArray(), 'permissions.can_send_messages');
        } catch (\Throwable $exception) {
            Log::warning('Failed to read quiz chat permissions', [
                'chat_id' => $chatId,
                'message' => $exception->getMessage(),
            ]);

            $permission = null;
        }

        return $this->membersCanPost[$chatId] = $permission === null || (bool) $permission;
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

    /** This phase's reminder body. */
    private function text(string $phase, DailyQuiz $quiz): string
    {
        return match ($phase) {
            self::KICKOFF => 'سؤال اليوم مفتوح 🎯 خذ لك دقيقة وجاوب',
            self::OPENER => $this->opener($quiz),
            self::TOPIC => $this->topic($quiz),
            self::REFLOAT => $this->refloat($quiz),
            self::MOMENTUM => $this->momentum($quiz),
            self::NIGHT => 'قبل ما تنام 🌙 سؤال اليوم لسه مفتوح',
            self::LATENIGHT => 'ساهر؟ 🌜 سؤال اليوم لسه ينتظر إجابتك',
            self::MORNING => $this->morning($quiz),
            self::TRAP => $this->trap($quiz),
            self::HINT => $this->hint($quiz),
            self::LASTCALL => $this->lastCall($quiz),
            self::CLOSING => 'آخر فرصة ⏳ سؤال اليوم يقفل بعد شوي',
            default => 'سؤال اليوم لسه مفتوح — جاوب قبل ما يقفل',
        };
    }

    /** Open the day on the turnout so far, or on the empty board. */
    private function opener(DailyQuiz $quiz): string
    {
        $participants = $quiz->answers()->count();

        return $participants === 0
            ? 'سؤال اليوم نازل ولسه ما جاوب عليه أحد — كن أول واحد'
            : 'سؤال اليوم نازل، وجاوب عليه '.ArabicPlural::people($participants).' — وأنت؟';
    }

    /** Taunt with the share that got it wrong, or with the empty board. */
    private function refloat(DailyQuiz $quiz): string
    {
        $participants = $quiz->answers()->count();

        return $participants === 0
            ? 'سؤال اليوم لسه بلا إجابات، بتقدر عليه؟'
            : 'سؤال اليوم غلطوا فيه '.$this->percentOf($quiz, false, $participants).'%، بتقدر عليه؟';
    }

    /** Re-float in the morning on accuracy so far, or on the empty board. */
    private function morning(DailyQuiz $quiz): string
    {
        $participants = $quiz->answers()->count();

        return $participants === 0
            ? 'صباح الخير ☕ سؤال اليوم لسه ينتظر أول إجابة'
            : 'صباح الخير ☕ نسبة الإجابات الصحيحة في سؤال اليوم '.$this->percentOf($quiz, true, $participants).'% — تقدر ترفعها؟';
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
     * Tease the subject the question was generated from. A quiz with no topic
     * (hand-written, or a deleted topic) gets the plain nudge.
     */
    private function topic(DailyQuiz $quiz): string
    {
        $name = $quiz->topic?->name;

        return filled($name)
            ? 'سؤال اليوم من «'.$this->escape($name).'» — تحسب نفسك قوي فيه؟'
            : 'سؤال اليوم فوق ☝️ جرّب حظك فيه';
    }

    /** Quote the answers that landed in the last hour, or name the quiet. */
    private function momentum(DailyQuiz $quiz): string
    {
        $recent = $quiz->answers()->where('answered_at', '>=', now()->subHour())->count();

        return $recent === 0
            ? 'الشات هادي 😴 وسؤال اليوم لسه مفتوح'
            : 'وصلتنا '.ArabicPlural::answers($recent).' في آخر ساعة 🔥 لا تتأخر';
    }

    /**
     * Warn off the wrong option the crowd is falling for hardest, as a share
     * of everyone who answered — or, with nothing wrong to warn about, praise
     * the clean board instead.
     */
    private function trap(DailyQuiz $quiz): string
    {
        $participants = $quiz->answers()->count();

        $mostPicked = $quiz->answers()
            ->where('is_correct', false)
            ->selectRaw('selected_option, count(*) as votes')
            ->groupBy('selected_option')
            ->orderByDesc('votes')
            ->first();

        if ($participants === 0) {
            return 'ما جاوب أحد على سؤال اليوم لين الآن — افتحها أنت';
        }

        if ($mostPicked === null) {
            return 'كل الإجابات صح لين الآن ✅ تحافظ على النسبة؟';
        }

        return 'أكثر إجابة غلط في سؤال اليوم اختارها '.(int) round($mostPicked->votes / $participants * 100).'% — لا تقع فيها';
    }

    /** The stored non-spoiler hint, or the plain "no hint today" line. */
    private function hint(DailyQuiz $quiz): string
    {
        return filled($quiz->hint)
            ? 'تلميح لسؤال اليوم: '.$this->escape($quiz->hint)
            : 'ما فيه تلميح لسؤال اليوم 😄 جاوب باللي تعرفه';
    }

    /**
     * The "closes soon" nudge, carrying the blunter second hint. Questions
     * authored before the second hint existed fall back to the subtle one, and
     * a quiz with neither gets the bare line.
     */
    private function lastCall(DailyQuiz $quiz): string
    {
        $line = 'قرب يقفل سؤال اليوم';
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
