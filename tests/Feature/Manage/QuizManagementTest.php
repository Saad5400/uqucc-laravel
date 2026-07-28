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

/**
 * A complete, valid question payload — the shape the editor dialog submits.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function quizPayload(array $overrides = []): array
{
    return [
        'quiz_topic_id' => null,
        'quiz_date' => today()->toDateString(),
        'question' => 'سؤال؟',
        'body' => null,
        'options' => ['أ', 'ب', 'ج', 'د'],
        'correct_option' => 0,
        'explanation' => null,
        'hint' => null,
        'obvious_hint' => null,
        ...$overrides,
    ];
}

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
            ->has('quizzes.data', 1)
            ->where('limits.question', 300)
            ->where('limits.hint', 120)
            ->where('today', today()->toDateString())
            ->where('todayQuizStatus', 'ready')
            ->has('weeklyTop')
            ->has('allTimeTop')
            ->has('groupChats'));
});

it('paginates the questions list at five per page', function () {
    DailyQuiz::factory()->count(7)->sequence(fn ($sequence) => ['quiz_date' => today()->subDays($sequence->index)])->create();

    $this->actingAs($this->admin)
        ->get('/manage/quiz')
        ->assertInertia(fn (Assert $page) => $page
            ->has('quizzes.data', 5)
            ->where('quizzes.total', 7)
            ->where('quizzes.per_page', 5)
            ->where('quizzes.last_page', 2));

    $this->actingAs($this->admin)
        ->get('/manage/quiz?page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('quizzes.data', 2)
            ->where('quizzes.current_page', 2));
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

it('edits every field of a ready quiz', function () {
    $quiz = DailyQuiz::factory()->create(['quiz_date' => today()]);
    $topic = QuizTopic::factory()->create();

    $this->actingAs($this->admin)
        ->put("/manage/quiz/quizzes/{$quiz->id}", quizPayload([
            'quiz_topic_id' => $topic->id,
            'quiz_date' => today()->addDay()->toDateString(),
            'question' => 'سؤال معدّل؟',
            'body' => "في الكود:\n```py\nx = 1\n```",
            'options' => ['أ', 'ب', 'ج', 'د'],
            'correct_option' => 3,
            'explanation' => 'لأن كذا.',
            'hint' => 'فكّر في الأساس.',
            'obvious_hint' => 'الجواب قريب من الخيار الأخير.',
        ]))
        ->assertRedirect()
        ->assertSessionHas('success');

    $quiz->refresh();

    expect($quiz->quiz_topic_id)->toBe($topic->id)
        ->and($quiz->quiz_date->toDateString())->toBe(today()->addDay()->toDateString())
        ->and($quiz->question)->toBe('سؤال معدّل؟')
        ->and($quiz->body)->toBe("في الكود:\n```py\nx = 1\n```")
        ->and($quiz->options)->toBe(['أ', 'ب', 'ج', 'د'])
        ->and($quiz->correct_option)->toBe(3)
        ->and($quiz->explanation)->toBe('لأن كذا.')
        ->and($quiz->hint)->toBe('فكّر في الأساس.')
        ->and($quiz->obvious_hint)->toBe('الجواب قريب من الخيار الأخير.');
});

it('clears the hints when they are submitted blank', function () {
    $quiz = DailyQuiz::factory()->create();

    expect($quiz->hint)->not->toBeNull();

    $this->actingAs($this->admin)
        ->put("/manage/quiz/quizzes/{$quiz->id}", quizPayload(['hint' => '', 'obvious_hint' => '   ']))
        ->assertRedirect()
        ->assertSessionHas('success');

    $quiz->refresh();

    expect($quiz->hint)->toBeNull()
        ->and($quiz->obvious_hint)->toBeNull();
});

it('writes a question by hand without the AI', function () {
    $topic = QuizTopic::factory()->create();

    $this->actingAs($this->admin)
        ->post('/manage/quiz/quizzes', quizPayload([
            'quiz_topic_id' => $topic->id,
            'quiz_date' => today()->addDays(2)->toDateString(),
            'question' => 'سؤال بخط اليد؟',
            'correct_option' => 2,
            'hint' => 'تلميح.',
        ]))
        ->assertRedirect()
        ->assertSessionHas('success');

    $quiz = DailyQuiz::query()->sole();

    expect($quiz->question)->toBe('سؤال بخط اليد؟')
        ->and($quiz->quiz_topic_id)->toBe($topic->id)
        ->and($quiz->quiz_date->toDateString())->toBe(today()->addDays(2)->toDateString())
        ->and($quiz->correct_option)->toBe(2)
        ->and($quiz->hint)->toBe('تلميح.')
        ->and($quiz->status)->toBe(DailyQuiz::STATUS_READY);
});

it('keeps one question per day', function () {
    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->post('/manage/quiz/quizzes', quizPayload())
        ->assertSessionHasErrors('quiz_date');

    expect(DailyQuiz::query()->count())->toBe(1);
});

it('lets a question keep its own date while editing', function () {
    $quiz = DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->put("/manage/quiz/quizzes/{$quiz->id}", quizPayload(['question' => 'نفس اليوم؟']))
        ->assertSessionHasNoErrors();

    expect($quiz->refresh()->question)->toBe('نفس اليوم؟');
});

it('refuses to move a question onto a day that is taken', function () {
    DailyQuiz::factory()->create(['quiz_date' => today()]);
    $quiz = DailyQuiz::factory()->create(['quiz_date' => today()->addDay()]);

    $this->actingAs($this->admin)
        ->put("/manage/quiz/quizzes/{$quiz->id}", quizPayload(['quiz_date' => today()->toDateString()]))
        ->assertSessionHasErrors('quiz_date');

    expect($quiz->refresh()->quiz_date->toDateString())->toBe(today()->addDay()->toDateString());
});

it('rejects a topic that does not exist', function () {
    $quiz = DailyQuiz::factory()->create();

    $this->actingAs($this->admin)
        ->put("/manage/quiz/quizzes/{$quiz->id}", quizPayload(['quiz_topic_id' => 9999]))
        ->assertSessionHasErrors('quiz_topic_id');
});

it('refuses to edit or delete a posted quiz', function () {
    $quiz = DailyQuiz::factory()->posted()->create();

    $this->actingAs($this->admin)
        ->put("/manage/quiz/quizzes/{$quiz->id}", quizPayload([
            'quiz_date' => $quiz->quiz_date->toDateString(),
            'question' => 'سؤال معدّل؟',
        ]))
        ->assertSessionHasErrors('quiz');

    $this->actingAs($this->admin)
        ->delete("/manage/quiz/quizzes/{$quiz->id}")
        ->assertSessionHasErrors('quiz');

    expect($quiz->refresh()->question)->not->toBe('سؤال معدّل؟')
        ->and(DailyQuiz::query()->count())->toBe(1);
});

it('enforces Telegram length limits when editing a quiz', function (array $payload, string $errorKey) {
    $quiz = DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->put("/manage/quiz/quizzes/{$quiz->id}", quizPayload($payload))
        ->assertSessionHasErrors($errorKey);
})->with([
    'long question' => [['question' => str_repeat('س', 301)], 'question'],
    'empty question' => [['question' => ''], 'question'],
    'long option' => [['options' => [str_repeat('س', 101), 'ب', 'ج', 'د']], 'options.0'],
    'three options' => [['options' => ['أ', 'ب', 'ج']], 'options'],
    'duplicate options' => [['options' => ['أ', 'أ', 'ج', 'د']], 'options.0'],
    'blank option' => [['options' => ['أ', '   ', 'ج', 'د']], 'options.1'],
    'long explanation' => [['explanation' => str_repeat('س', 201)], 'explanation'],
    'long body' => [['body' => str_repeat('س', 701)], 'body'],
    'long hint' => [['hint' => str_repeat('س', 121)], 'hint'],
    'long obvious hint' => [['obvious_hint' => str_repeat('س', 121)], 'obvious_hint'],
    'correct out of range' => [['correct_option' => 4], 'correct_option'],
    'missing date' => [['quiz_date' => null], 'quiz_date'],
    'invalid date' => [['quiz_date' => 'ليس تاريخاً'], 'quiz_date'],
]);

it('enforces the same limits when writing a question by hand', function () {
    $this->actingAs($this->admin)
        ->post('/manage/quiz/quizzes', quizPayload(['hint' => str_repeat('س', 121)]))
        ->assertSessionHasErrors('hint');

    expect(DailyQuiz::query()->count())->toBe(0);
});

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

    Queue::assertPushed(GenerateDailyQuizJob::class, fn (GenerateDailyQuizJob $job): bool => $job->queue === 'ai');
});

it('queues generation from a chosen topic', function () {
    Queue::fake();
    $topic = QuizTopic::factory()->create();

    $this->actingAs($this->admin)
        ->post('/manage/quiz/generate', ['topic_id' => $topic->id])
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(GenerateDailyQuizJob::class);
});

it('rejects generation from an inactive topic', function () {
    Queue::fake();
    $topic = QuizTopic::factory()->create(['is_active' => false]);

    $this->actingAs($this->admin)
        ->post('/manage/quiz/generate', ['topic_id' => $topic->id])
        ->assertSessionHasErrors('topic_id');

    Queue::assertNothingPushed();
});

it('re-rolls a ready quiz that already exists today', function () {
    Queue::fake();
    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->post('/manage/quiz/generate')
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(GenerateDailyQuizJob::class);
});

it('refuses to regenerate a quiz that is already posted', function () {
    Queue::fake();
    DailyQuiz::factory()->posted()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->post('/manage/quiz/generate')
        ->assertSessionHasErrors('generate');

    Queue::assertNothingPushed();
});
