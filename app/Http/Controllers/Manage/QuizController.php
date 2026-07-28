<?php

namespace App\Http\Controllers\Manage;

use App\Ai\Quiz\QuizAuthor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\GenerateDailyQuizRequest;
use App\Http\Requests\Manage\UpdateQuizSettingsRequest;
use App\Jobs\GenerateDailyQuizJob;
use App\Models\DailyQuiz;
use App\Models\QuizPlayer;
use App\Models\QuizTopic;
use App\Models\TelegramChatSetting;
use App\Settings\QuizSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

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

    public function index(QuizSettings $settings): Response
    {
        $current = $this->currentQuiz();

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
            'topics' => $this->topics(),
            'currentQuiz' => $current === null ? null : $this->quizPayload($current),
            'upcoming' => $this->upcoming(),
            'pastCount' => DailyQuiz::query()->whereDate('quiz_date', '<', today())->count(),
            'limits' => $this->limits(),
            'today' => today()->toDateString(),
            'nextFreeDate' => $this->nextFreeDate(),
            'todayQuizStatus' => DailyQuiz::forDate(today())?->status,
            'weeklyTop' => $this->leaderboard('weekly_points'),
            'allTimeTop' => $this->leaderboard('total_points'),
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
            'body' => QuizAuthor::MAX_BODY_CHARS,
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
            'body' => $quiz->body,
            'options' => $quiz->options,
            'correct_option' => $quiz->correct_option,
            'explanation' => $quiz->explanation,
            'hint' => $quiz->hint,
            'obvious_hint' => $quiz->obvious_hint,
            'status' => $quiz->status,
            'topic' => $quiz->topic?->name,
            'posted_at' => $quiz->posted_at?->toISOString(),
            'answers_count' => $quiz->answers_count ?? 0,
            'correct_answers_count' => $quiz->correct_answers_count ?? 0,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function leaderboard(string $column): Collection
    {
        return QuizPlayer::query()
            ->where($column, '>', 0)
            ->orderByDesc($column)
            ->orderByDesc('best_streak')
            ->orderBy('id')
            ->limit(self::LEADERBOARD_LIMIT)
            ->get()
            ->map(fn (QuizPlayer $player): array => [
                'id' => $player->id,
                'name' => $player->displayName(),
                'username' => $player->username,
                'points' => $player->{$column},
                'current_streak' => $player->current_streak,
                'answers_count' => $player->answers_count,
            ])
            ->toBase();
    }
}
