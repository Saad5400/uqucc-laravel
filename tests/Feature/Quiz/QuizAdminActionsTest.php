<?php

use App\Ai\Admin\Actions\AdminActionException;
use App\Ai\Admin\Actions\AdminActionRegistry;
use App\Ai\Admin\Actions\Quiz\CreateQuizTopicAction;
use App\Ai\Admin\Actions\Quiz\DeleteQuizTopicAction;
use App\Ai\Admin\Actions\Quiz\GetDailyQuizAction;
use App\Ai\Admin\Actions\Quiz\GetQuizLeaderboardAction;
use App\Ai\Admin\Actions\Quiz\ListQuizTopicsAction;
use App\Ai\Admin\Actions\Quiz\RegenerateDailyQuizAction;
use App\Ai\Admin\Actions\Quiz\UpdateDailyQuizAction;
use App\Ai\Admin\Actions\Quiz\UpdateQuizTopicAction;
use App\Ai\Quiz\QuizAuthoringAgent;
use App\Models\DailyQuiz;
use App\Models\QuizPlayer;
use App\Models\QuizTopic;
use App\Models\User;
use App\Settings\AiSettings;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('registers every quiz action on the unified registry', function () {
    $names = array_keys(app(AdminActionRegistry::class)->all());

    expect($names)->toContain(
        'list_quiz_topics',
        'create_quiz_topic',
        'update_quiz_topic',
        'delete_quiz_topic',
        'get_daily_quiz',
        'update_daily_quiz',
        'regenerate_daily_quiz',
        'get_quiz_leaderboard',
    );
});

it('lists quiz topics', function () {
    QuizTopic::factory()->create(['name' => 'الخوارزميات']);

    $result = app(ListQuizTopicsAction::class)->handle([], $this->user);

    expect($result->message)->toContain('الخوارزميات');
});

it('creates a quiz topic', function () {
    $result = app(CreateQuizTopicAction::class)->handle(
        ['name' => 'الشبكات', 'is_spotlight' => true],
        $this->user,
    );

    expect($result->message)->toContain('الشبكات')
        ->and(QuizTopic::query()->where('name', 'الشبكات')->where('is_spotlight', true)->exists())->toBeTrue();
});

it('rejects a blank topic name', function () {
    app(CreateQuizTopicAction::class)->handle(['name' => '   '], $this->user);
})->throws(AdminActionException::class);

it('updates only the provided fields of a topic', function () {
    $topic = QuizTopic::factory()->create(['name' => 'قديم', 'is_active' => true]);

    app(UpdateQuizTopicAction::class)->handle(
        ['topic_id' => $topic->id, 'is_active' => false],
        $this->user,
    );

    $topic->refresh();

    expect($topic->name)->toBe('قديم')
        ->and($topic->is_active)->toBeFalse();
});

it('deletes a quiz topic', function () {
    $topic = QuizTopic::factory()->create();

    app(DeleteQuizTopicAction::class)->handle(['topic_id' => $topic->id], $this->user);

    expect(QuizTopic::query()->count())->toBe(0);
});

it('shows today\'s quiz with the correct option marked', function () {
    DailyQuiz::factory()->create([
        'quiz_date' => today(),
        'question' => 'ما ناتج 2 + 2؟',
        'options' => ['3', '4', '5', '6'],
        'correct_option' => 1,
    ]);

    $result = app(GetDailyQuizAction::class)->handle([], $this->user);

    expect($result->message)->toContain('ما ناتج 2 + 2؟')
        ->toContain('✅ 1. 4');
});

it('edits a ready quiz and refuses a posted one', function () {
    $ready = DailyQuiz::factory()->create(['quiz_date' => today()]);

    $payload = [
        'quiz_id' => $ready->id,
        'question' => 'سؤال معدّل؟',
        'body' => "في الكود:\n```py\nx = 1\n```",
        'options' => ['أ', 'ب', 'ج', 'د'],
        'correct_option' => 2,
        'explanation' => null,
    ];

    app(UpdateDailyQuizAction::class)->handle($payload, $this->user);

    expect($ready->refresh()->question)->toBe('سؤال معدّل؟')
        ->and($ready->body)->toBe("في الكود:\n```py\nx = 1\n```")
        ->and($ready->correct_option)->toBe(2);

    $posted = DailyQuiz::factory()->posted()->create(['quiz_date' => today()->subDay()]);

    expect(fn () => app(UpdateDailyQuizAction::class)->handle([...$payload, 'quiz_id' => $posted->id], $this->user))
        ->toThrow(AdminActionException::class);
});

it('regenerates today\'s quiz by replacing the ready one', function () {
    config()->set('ai.providers.openrouter.key', 'test-key');
    $ai = app(AiSettings::class);
    $ai->ai_enabled = true;
    $ai->daily_budget_usd = 5.0;
    $ai->save();

    QuizTopic::factory()->create();
    $old = DailyQuiz::factory()->create(['quiz_date' => today(), 'question' => 'سؤال قديم؟']);

    QuizAuthoringAgent::fake([json_encode([
        'question' => 'سؤال جديد تماماً؟',
        'options' => ['أ', 'ب', 'ج', 'د'],
        'correct_option' => 0,
        'explanation' => 'شرح.',
    ], JSON_UNESCAPED_UNICODE)]);

    $result = app(RegenerateDailyQuizAction::class)->handle([], $this->user);

    expect($result->message)->toContain('سؤال جديد تماماً؟')
        ->and(DailyQuiz::query()->where('id', $old->id)->exists())->toBeFalse()
        ->and(DailyQuiz::forDate(today())->question)->toBe('سؤال جديد تماماً؟');
});

it('refuses to regenerate a posted quiz', function () {
    config()->set('ai.providers.openrouter.key', 'test-key');
    $ai = app(AiSettings::class);
    $ai->ai_enabled = true;
    $ai->daily_budget_usd = 5.0;
    $ai->save();

    DailyQuiz::factory()->posted()->create(['quiz_date' => today()]);

    app(RegenerateDailyQuizAction::class)->handle([], $this->user);
})->throws(AdminActionException::class);

it('renders the leaderboard', function () {
    QuizPlayer::factory()->create(['first_name' => 'أحمد', 'weekly_points' => 40, 'total_points' => 120, 'current_streak' => 3]);

    $result = app(GetQuizLeaderboardAction::class)->handle([], $this->user);

    expect($result->message)->toContain('أحمد')
        ->toContain('هذا الأسبوع');
});
