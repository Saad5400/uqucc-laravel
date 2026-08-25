<?php

namespace App\Http\Controllers\Manage;

use App\Ai\Quiz\QuizAuthor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\GenerateDailyQuizRequest;
use App\Http\Requests\Manage\UpdateQuizScheduleRequest;
use App\Http\Requests\Manage\UpdateQuizSettingsRequest;
use App\Jobs\GenerateDailyQuizJob;
use App\Models\DailyQuiz;
use App\Models\QuizPlayer;
use App\Models\QuizTopic;
use App\Models\TelegramChatSetting;
use App\Services\Quiz\QuizLeaderboard;
use App\Services\Quiz\QuizPoster;
use App\Services\Quiz\QuizSchedule;
use App\Settings\QuizSettings;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The daily quiz control room. The front door shows the one question the
 * admin can actually act on right now — today's while it is still editable,
 * otherwise the nearest upcoming one — plus the topics, settings and
 * leaderboards. Everything already posted, and the rest of the upcoming
 * queue, lives one level in at {@see self::archive()}.
 */
class QuizController extends Controller
{
    /** How many questions each page of the archive shows. */
    private const QUIZZES_PER_PAGE = 10;

    /** How many players each leaderboard column shows. */
    private const LEADERBOARD_LIMIT = 10;

    /** How far ahead the generate dialog looks for a free day before giving up. */
    private const MAX_SCHEDULING_HORIZON_DAYS = 365;

    public function index(QuizSettings $settings, QuizSchedule $schedule, QuizLeaderboard $leaderboard): Response
    {
        $current = $this->currentQuiz();
        $today = DailyQuiz::forDate(today());

        return Inertia::render('manage/quiz/Index', [
            'settings' => [
                'enabled' => $settings->enabled,
                'reminders_enabled' => $settings->reminders_enabled,
                'chat_ids' => $settings->chat_ids,
            ],
            'groupChats' => TelegramChatSetting::query()
                ->whereIn('type', ['group', 'supergroup'])
                ->orderBy('title')
                ->get()
                ->map(fn (TelegramChatSetting $chat): array => [
                    'chat_id' => (string) $chat->chat_id,
                    'title' => $chat->title,
                ]),
            'schedule' => [
                'post_time' => $schedule->postTimeFor(null),
                'today_post_time' => $today?->post_time,
                'today_posts_at' => $schedule->todayPostsAt()->toISOString(),
            ],
            'topics' => $this->topics(),
            'currentQuiz' => $current === null ? null : $this->quizPayload($current),
            'upcoming' => $this->upcoming(),
            'pastCount' => DailyQuiz::query()->whereDate('quiz_date', '<', today())->count(),
            'limits' => $this->limits(),
            'today' => today()->toDateString(),
            'nextFreeDate' => $this->nextFreeDate(),
            'todayQuizStatus' => $today?->status,
            'weeklyTop' => $this->leaderboard(
                $leaderboard->weekly(self::LEADERBOARD_LIMIT),
                fn (QuizPlayer $player): int => $player->weekly_points,
            ),
            'windowTop' => $this->leaderboard(
                $leaderboard->window(self::LEADERBOARD_LIMIT),
                fn (QuizPlayer $player): int => (int) $player->window_points,
            ),
            'windowDays' => QuizLeaderboard::WINDOW_DAYS,
        ]);
    }

    /**
     * Every question, split by whether it is still ahead of us. The two
     * halves want opposite orderings — the queue reads forward from today,
     * history reads backward from the most recent — so they are separate
     * scopes rather than one list nobody can scan.
     */
    public function archive(Request $request): Response
    {
        $scope = $request->string('scope')->toString() === 'past' ? 'past' : 'upcoming';

        $quizzes = DailyQuiz::query()
            ->with('topic:id,name')
            ->withCount([
                'answers',
                'answers as correct_answers_count' => fn ($query) => $query->where('is_correct', true),
            ])
            ->when(
                $scope === 'past',
                fn ($query) => $query->whereDate('quiz_date', '<', today())->orderByDesc('quiz_date'),
                fn ($query) => $query->whereDate('quiz_date', '>=', today())->orderBy('quiz_date'),
            )
            ->paginate(self::QUIZZES_PER_PAGE)
            ->withQueryString()
            ->through(fn (DailyQuiz $quiz): array => $this->quizPayload($quiz));

        return Inertia::render('manage/quiz/Archive', [
            'scope' => $scope,
            'quizzes' => $quizzes,
            'counts' => [
                'upcoming' => DailyQuiz::query()->whereDate('quiz_date', '>=', today())->count(),
                'past' => DailyQuiz::query()->whereDate('quiz_date', '<', today())->count(),
            ],
            'topics' => $this->topics(),
            'limits' => $this->limits(),
            'today' => today()->toDateString(),
        ]);
    }

    public function updateSettings(UpdateQuizSettingsRequest $request, QuizSettings $settings): RedirectResponse
    {
        $settings->enabled = $request->boolean('enabled');
        $settings->chat_ids = array_values($request->validated('chat_ids'));

        if ($request->has('reminders_enabled')) {
            $settings->reminders_enabled = $request->boolean('reminders_enabled');
        }

        $settings->save();

        return back()->with('success', 'تم حفظ إعدادات سؤال اليوم.');
    }

    /**
     * Generate a question on demand (queued — the authoring model is slow).
     * The nightly schedule fills today; this button covers the first run,
     * re-rolling a question the admin doesn't like, and building a buffer of
     * upcoming days. A day that already holds a `ready` question is
     * regenerated in place; a posted one is never touched.
     */
    public function generate(GenerateDailyQuizRequest $request): RedirectResponse
    {
        $date = $request->date('date')?->startOfDay() ?? today();
        $existing = DailyQuiz::forDate($date);
        $replace = $existing !== null;

        if ($existing !== null && ! $existing->isReady()) {
            return back()->withErrors(['generate' => 'سؤال هذا اليوم منشور بالفعل — لا يمكن إعادة توليده.']);
        }

        $topicId = $request->integer('topic_id') ?: null;

        GenerateDailyQuizJob::dispatch($date->toDateString(), $topicId, $replace);

        $day = $date->isSameDay(today()) ? 'سؤال اليوم' : 'سؤال يوم '.$date->toDateString();

        return back()->with('success', $replace
            ? "بدأ إعادة توليد {$day} — سيتحدّث خلال دقائق."
            : "بدأ توليد {$day} — سيظهر خلال دقائق.");
    }

    /**
     * Send today's question to the groups right now, ahead of its scheduled
     * time — and re-send it when it already went out, the escape hatch for a
     * poll someone deleted from the group. Runs inline rather than queued so
     * the admin sees the Telegram outcome on the spot.
     */
    public function post(QuizSettings $settings, QuizPoster $poster): RedirectResponse
    {
        if (! $settings->isConfigured()) {
            return back()->withErrors(['post' => 'سؤال اليوم غير مفعّل أو بلا مجموعات مستهدفة.']);
        }

        $quiz = DailyQuiz::forDate(today());

        if ($quiz === null) {
            return back()->withErrors(['post' => 'لا يوجد سؤال لهذا اليوم — ولّده أولاً.']);
        }

        if (! $quiz->isReady() && ! $quiz->isPosted()) {
            return back()->withErrors(['post' => 'سؤال اليوم أُغلق ولا يمكن نشره من جديد.']);
        }

        $reposting = $quiz->isPosted();

        try {
            $poster->post($quiz, force: true);
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(['post' => $exception->getMessage()]);
        }

        return back()->with('success', $reposting
            ? 'أُعيد نشر سؤال اليوم في المجموعات — الإجابات المسجّلة سابقاً محفوظة.'
            : 'نُشر سؤال اليوم في المجموعات.');
    }

    /**
     * Move the posting time — for every day (the default) or for today's
     * question alone, which then goes out at its own time and leaves the
     * default untouched. Clearing the one-day time returns it to the default.
     */
    public function updateSchedule(UpdateQuizScheduleRequest $request, QuizSettings $settings): RedirectResponse
    {
        $postTime = $request->validated('post_time');

        if ($request->validated('scope') === UpdateQuizScheduleRequest::SCOPE_DEFAULT) {
            $settings->post_time = $postTime;
            $settings->save();

            return back()->with('success', "تم ضبط موعد النشر اليومي على {$postTime}.");
        }

        $quiz = DailyQuiz::forDate(today());

        if ($quiz === null) {
            return back()->withErrors(['post_time' => 'لا يوجد سؤال لهذا اليوم لإعادة جدولته.']);
        }

        if (! $quiz->isReady()) {
            return back()->withErrors(['post_time' => 'سؤال اليوم نُشر بالفعل — لا يمكن إعادة جدولته.']);
        }

        $quiz->update(['post_time' => $postTime]);

        return back()->with('success', $postTime === null
            ? 'عاد سؤال اليوم إلى موعد النشر الافتراضي.'
            : "سيُنشر سؤال اليوم الساعة {$postTime} بدل الموعد الافتراضي.");
    }

    /**
     * The question the admin can act on right now: today's while it is still
     * editable, otherwise the nearest upcoming one. A `ready` question dated
     * before today never posts (the poster only looks today up), so it is
     * left to the archive rather than shown as if it were live.
     */
    private function currentQuiz(): ?DailyQuiz
    {
        return DailyQuiz::query()
            ->with('topic:id,name')
            ->withCount([
                'answers',
                'answers as correct_answers_count' => fn ($query) => $query->where('is_correct', true),
            ])
            ->where('status', DailyQuiz::STATUS_READY)
            ->whereDate('quiz_date', '>=', today())
            ->orderBy('quiz_date')
            ->first();
    }

    /**
     * The upcoming queue as a compact schedule strip — enough to see how many
     * days are covered and where the gaps are without loading every question.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function upcoming(): Collection
    {
        return DailyQuiz::query()
            ->with('topic:id,name')
            ->whereDate('quiz_date', '>=', today())
            ->orderBy('quiz_date')
            ->get()
            ->map(fn (DailyQuiz $quiz): array => [
                'id' => $quiz->id,
                'quiz_date' => $quiz->quiz_date->toDateString(),
                'status' => $quiz->status,
                'topic' => $quiz->topic?->name,
            ])
            ->toBase();
    }

    /**
     * The first day from today on with no question yet — where a new
     * generation lands by default, so filling a buffer never means picking
     * dates by hand.
     */
    private function nextFreeDate(): string
    {
        $taken = DailyQuiz::query()
            ->whereDate('quiz_date', '>=', today())
            ->pluck('quiz_date')
            ->map(fn (Carbon $date): string => $date->toDateString())
            ->all();

        $date = today();

        for ($day = 0; $day < self::MAX_SCHEDULING_HORIZON_DAYS; $day++) {
            if (! in_array($date->toDateString(), $taken, true)) {
                break;
            }

            $date = $date->addDay();
        }

        return $date->toDateString();
    }

    /**
     * Telegram's hard caps, so the editor can show them while typing instead
     * of only rejecting on save.
     *
     * @return array<string, int>
     */
    private function limits(): array
    {
        return [
            'question' => QuizAuthor::MAX_QUESTION_CHARS,
            'option' => QuizAuthor::MAX_OPTION_CHARS,
            'explanation' => QuizAuthor::MAX_EXPLANATION_CHARS,
            'hint' => QuizAuthor::MAX_HINT_CHARS,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function topics(): Collection
    {
        return QuizTopic::query()
            ->orderByDesc('is_active')
            ->orderBy('is_spotlight')
            ->orderBy('name')
            ->get()
            ->map(fn (QuizTopic $topic): array => [
                'id' => $topic->id,
                'name' => $topic->name,
                'prompt_hint' => $topic->prompt_hint,
                'is_spotlight' => $topic->is_spotlight,
                'is_active' => $topic->is_active,
                'last_used_at' => $topic->last_used_at?->toISOString(),
                'awaiting_turn' => $topic->cycle_used_at === null,
            ])
            ->toBase();
    }

    /**
     * One question as the shared `QuizCard`/editor consume it — the same
     * shape on the front door and in the archive.
     *
     * @return array<string, mixed>
     */
    private function quizPayload(DailyQuiz $quiz): array
    {
        return [
            'id' => $quiz->id,
            'quiz_topic_id' => $quiz->quiz_topic_id,
            'quiz_date' => $quiz->quiz_date->toDateString(),
            'question' => $quiz->question,
            'options' => $quiz->options,
            'correct_option' => $quiz->correct_option,
            'explanation' => $quiz->explanation,
            'hint' => $quiz->hint,
            'obvious_hint' => $quiz->obvious_hint,
            'status' => $quiz->status,
            'post_time' => $quiz->post_time,
            'topic' => $quiz->topic?->name,
            'posted_at' => $quiz->posted_at?->toISOString(),
            'answers_count' => $quiz->answers_count ?? 0,
            'correct_answers_count' => $quiz->correct_answers_count ?? 0,
        ];
    }

    /**
     * @param  EloquentCollection<int, QuizPlayer>  $players
     * @param  callable(QuizPlayer): int  $points
     * @return Collection<int, array<string, mixed>>
     */
    private function leaderboard(EloquentCollection $players, callable $points): Collection
    {
        return $players
            ->map(fn (QuizPlayer $player): array => [
                'id' => $player->id,
                'name' => $player->displayName(),
                'username' => $player->username,
                'points' => $points($player),
                'current_streak' => $player->current_streak,
                'answers_count' => $player->answers_count,
            ])
            ->toBase();
    }
}
