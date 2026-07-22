<?php

use App\Jobs\GenerateDailyQuizJob;
use App\Models\DailyQuiz;
use App\Models\QuizTopic;
use App\Models\User;
use App\Settings\QuizSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('redirects guests to the login page', function () {
    $this->get('/manage/quiz')->assertRedirect('/manage/login');
});

it('renders the quiz page with settings, topics, quizzes and leaderboards', function () {
    QuizTopic::factory()->count(2)->create();
    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->get('/manage/quiz')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('manage/quiz/Index')
            ->has('settings', fn (Assert $settings) => $settings->has('enabled')->has('reminders_enabled')->has('chat_ids'))
            ->has('topics', 2)
            ->has('quizzes', 1)
            ->where('hasTodayQuiz', true)
            ->has('weeklyTop')
            ->has('allTimeTop')
            ->has('groupChats'));
});

it('saves the quiz settings with multiple groups', function () {
    $this->actingAs($this->admin)
        ->put('/manage/quiz/settings', ['enabled' => true, 'chat_ids' => ['-100999', '-100888']])
        ->assertRedirect()
        ->assertSessionHas('success');

    $settings = app(QuizSettings::class)->refresh();

    expect($settings->enabled)->toBeTrue()
        ->and($settings->chat_ids)->toBe(['-100999', '-100888']);
});

it('rejects a non-numeric chat id', function () {
    $this->actingAs($this->admin)
        ->put('/manage/quiz/settings', ['enabled' => true, 'chat_ids' => ['not-a-chat']])
        ->assertSessionHasErrors('chat_ids.0');
});

it('creates, updates and deletes a topic', function () {
    $this->actingAs($this->admin)
        ->post('/manage/quiz/topics', ['name' => 'الخوارزميات', 'prompt_hint' => null, 'is_spotlight' => false])
        ->assertRedirect()
        ->assertSessionHas('success');

    $topic = QuizTopic::query()->first();

    expect($topic->name)->toBe('الخوارزميات')
        ->and($topic->is_active)->toBeTrue();

    $this->actingAs($this->admin)
        ->put("/manage/quiz/topics/{$topic->id}", [
            'name' => 'الخوارزميات المتقدمة',
            'prompt_hint' => 'ركّز على التعقيد الزمني',
            'is_spotlight' => true,
            'is_active' => false,
        ])
        ->assertRedirect();

    $topic->refresh();

    expect($topic->name)->toBe('الخوارزميات المتقدمة')
        ->and($topic->prompt_hint)->toBe('ركّز على التعقيد الزمني')
        ->and($topic->is_spotlight)->toBeTrue()
        ->and($topic->is_active)->toBeFalse();

    $this->actingAs($this->admin)
        ->delete("/manage/quiz/topics/{$topic->id}")
        ->assertRedirect();

    expect(QuizTopic::query()->count())->toBe(0);
});

it('rejects a topic without a name', function () {
    $this->actingAs($this->admin)
        ->post('/manage/quiz/topics', ['name' => '', 'is_spotlight' => false])
        ->assertSessionHasErrors('name');
});

it('edits a ready quiz', function () {
    $quiz = DailyQuiz::factory()->create();

    $this->actingAs($this->admin)
        ->put("/manage/quiz/quizzes/{$quiz->id}", [
            'question' => 'سؤال معدّل؟',
            'options' => ['أ', 'ب', 'ج', 'د'],
            'correct_option' => 3,
            'explanation' => null,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $quiz->refresh();

    expect($quiz->question)->toBe('سؤال معدّل؟')
        ->and($quiz->options)->toBe(['أ', 'ب', 'ج', 'د'])
        ->and($quiz->correct_option)->toBe(3)
        ->and($quiz->explanation)->toBeNull();
});

it('refuses to edit or delete a posted quiz', function () {
    $quiz = DailyQuiz::factory()->posted()->create();

    $this->actingAs($this->admin)
        ->put("/manage/quiz/quizzes/{$quiz->id}", [
            'question' => 'سؤال معدّل؟',
            'options' => ['أ', 'ب', 'ج', 'د'],
            'correct_option' => 0,
            'explanation' => null,
        ])
        ->assertSessionHasErrors('quiz');

    $this->actingAs($this->admin)
        ->delete("/manage/quiz/quizzes/{$quiz->id}")
        ->assertSessionHasErrors('quiz');

    expect($quiz->refresh()->question)->not->toBe('سؤال معدّل؟')
        ->and(DailyQuiz::query()->count())->toBe(1);
});

it('enforces Telegram length limits when editing a quiz', function (array $payload, string $errorKey) {
    $quiz = DailyQuiz::factory()->create();

    $valid = [
        'question' => 'سؤال؟',
        'options' => ['أ', 'ب', 'ج', 'د'],
        'correct_option' => 0,
        'explanation' => null,
    ];

    $this->actingAs($this->admin)
        ->put("/manage/quiz/quizzes/{$quiz->id}", [...$valid, ...$payload])
        ->assertSessionHasErrors($errorKey);
})->with([
    'long question' => [['question' => str_repeat('س', 301)], 'question'],
    'long option' => [['options' => [str_repeat('س', 101), 'ب', 'ج', 'د']], 'options.0'],
    'three options' => [['options' => ['أ', 'ب', 'ج']], 'options'],
    'duplicate options' => [['options' => ['أ', 'أ', 'ج', 'د']], 'options.0'],
    'long explanation' => [['explanation' => str_repeat('س', 201)], 'explanation'],
    'correct out of range' => [['correct_option' => 4], 'correct_option'],
]);

it('deletes a ready quiz', function () {
    $quiz = DailyQuiz::factory()->create();

    $this->actingAs($this->admin)
        ->delete("/manage/quiz/quizzes/{$quiz->id}")
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(DailyQuiz::query()->count())->toBe(0);
});

it('queues on-demand generation for today', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->post('/manage/quiz/generate')
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(GenerateDailyQuizJob::class);
});

it('refuses on-demand generation while today\'s quiz exists', function () {
    Queue::fake();
    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->post('/manage/quiz/generate')
        ->assertSessionHasErrors('generate');

    Queue::assertNothingPushed();
});
