<?php

namespace App\Http\Controllers\Manage;

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
use Inertia\Inertia;
use Inertia\Response;

/**
 * The daily quiz control room: feature settings (on/off + target group), the
 * AI topic list, the generated questions (editable until posted), and the
 * leaderboards mirroring what the bot shows in the group.
 */
class QuizController extends Controller
{
    /** How many questions each page of the questions list shows. */
    private const QUIZZES_PER_PAGE = 5;

    /** How many players each leaderboard column shows. */
    private const LEADERBOARD_LIMIT = 10;

    public function index(QuizSettings $settings): Response
    {
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
            'topics' => QuizTopic::query()
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
                ]),
            'quizzes' => DailyQuiz::query()
                ->with('topic:id,name')
                ->withCount([
                    'answers',
                    'answers as correct_answers_count' => fn ($query) => $query->where('is_correct', true),
                ])
                ->orderByDesc('quiz_date')
                ->paginate(self::QUIZZES_PER_PAGE)
                ->withQueryString()
                ->through(fn (DailyQuiz $quiz): array => [
                    'id' => $quiz->id,
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
                    'answers_count' => $quiz->answers_count,
                    'correct_answers_count' => $quiz->correct_answers_count,
                ]),
            'todayQuizStatus' => DailyQuiz::forDate(today())?->status,
            'weeklyTop' => $this->leaderboard('weekly_points'),
            'allTimeTop' => $this->leaderboard('total_points'),
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
     * Generate today's question on demand (queued — the authoring model is
     * slow). The nightly schedule normally does this; the button covers the
     * first run and re-rolling: when a not-yet-posted question already exists
     * it is regenerated in place, optionally from an admin-chosen topic.
     */
    public function generate(GenerateDailyQuizRequest $request): RedirectResponse
    {
        $existing = DailyQuiz::forDate(today());
        $replace = $existing !== null;

        if ($existing !== null && ! $existing->isReady()) {
            return back()->withErrors(['generate' => 'سؤال اليوم منشور بالفعل — لا يمكن إعادة توليده.']);
        }

        $topicId = $request->integer('topic_id') ?: null;

        GenerateDailyQuizJob::dispatch($topicId, $replace);

        return back()->with('success', $replace
            ? 'بدأ إعادة توليد سؤال اليوم — سيتحدّث في القائمة خلال دقائق.'
            : 'بدأ توليد سؤال اليوم — سيظهر في القائمة خلال دقائق.');
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function leaderboard(string $column): \Illuminate\Support\Collection
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
