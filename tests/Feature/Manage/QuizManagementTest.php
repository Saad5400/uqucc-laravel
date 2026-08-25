<?php

use App\Jobs\GenerateDailyQuizJob;
use App\Services\Quiz\QuizLeaderboard;
use App\Models\DailyQuiz;
use App\Models\QuizAnswer;
use App\Models\QuizTopic;
use App\Models\User;
use App\Services\Quiz\QuizImageRenderer;
use App\Services\Quiz\QuizPoster;
use App\Services\Quiz\QuizSchedule;
use App\Settings\QuizSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeTelegramApi;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    // Posting renders the question to an image via a headless browser, which
    // the tests do not have; stub the renderer so the posting paths run.
    $this->app->instance(QuizImageRenderer::class, new class extends QuizImageRenderer
    {
        public function render(DailyQuiz $quiz): string
        {
            return 'fake-png-bytes';
        }
    });
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

it('renders the quiz page with settings, topics, the current question and leaderboards', function () {
    QuizTopic::factory()->count(2)->create();
    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->get('/manage/quiz')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('manage/quiz/Index')
            ->has('settings', fn (Assert $settings) => $settings->has('enabled')->has('reminders_enabled')->has('chat_ids'))
            ->has('topics', 2)
            ->where('currentQuiz.quiz_date', today()->toDateString())
            ->has('upcoming', 1)
            ->where('pastCount', 0)
            ->where('limits.question', 1200)
            ->where('limits.hint', 120)
            ->where('today', today()->toDateString())
            ->where('todayQuizStatus', 'ready')
            ->has('weeklyTop')
            ->has('windowTop')
            ->where('windowDays', QuizLeaderboard::WINDOW_DAYS)
            ->has('groupChats'));
});

it('shows today\'s question as the current one while it is still editable', function () {
    DailyQuiz::factory()->create(['quiz_date' => today(), 'question' => 'سؤال اليوم؟']);
    DailyQuiz::factory()->create(['quiz_date' => today()->addDay(), 'question' => 'سؤال الغد؟']);

    $this->actingAs($this->admin)
        ->get('/manage/quiz')
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentQuiz.question', 'سؤال اليوم؟')
            ->has('upcoming', 2));
});

it('falls through to the nearest upcoming question once today is posted', function () {
    DailyQuiz::factory()->posted()->create(['quiz_date' => today()]);
    DailyQuiz::factory()->create(['quiz_date' => today()->addDays(3), 'question' => 'بعد ثلاثة أيام؟']);
    DailyQuiz::factory()->create(['quiz_date' => today()->addDay(), 'question' => 'سؤال الغد؟']);

    $this->actingAs($this->admin)
        ->get('/manage/quiz')
        ->assertInertia(fn (Assert $page) => $page
            ->where('todayQuizStatus', 'posted')
            ->where('currentQuiz.question', 'سؤال الغد؟')
            ->where('currentQuiz.quiz_date', today()->addDay()->toDateString()));
});

it('shows no current question when nothing ahead is editable', function () {
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDay()]);
    DailyQuiz::factory()->posted()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->get('/manage/quiz')
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentQuiz', null)
            ->where('pastCount', 1));
});

it('never promotes a stale past-dated ready question to the front door', function () {
    DailyQuiz::factory()->create(['quiz_date' => today()->subDays(2), 'question' => 'سؤال قديم لم يُنشر؟']);

    $this->actingAs($this->admin)
        ->get('/manage/quiz')
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentQuiz', null)
            ->where('pastCount', 1));
});

it('offers the first unscheduled day as the generation default', function () {
    DailyQuiz::factory()->create(['quiz_date' => today()]);
    DailyQuiz::factory()->create(['quiz_date' => today()->addDay()]);
    DailyQuiz::factory()->create(['quiz_date' => today()->addDays(3)]);

    $this->actingAs($this->admin)
        ->get('/manage/quiz')
        ->assertInertia(fn (Assert $page) => $page->where('nextFreeDate', today()->addDays(2)->toDateString()));
});

it('lists upcoming questions in the archive, nearest day first', function () {
    DailyQuiz::factory()
        ->count(12)
        ->sequence(fn ($sequence) => ['quiz_date' => today()->addDays($sequence->index)])
        ->create();

    $this->actingAs($this->admin)
        ->get('/manage/quiz/archive')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('manage/quiz/Archive')
            ->where('scope', 'upcoming')
            ->has('quizzes.data', 10)
            ->where('quizzes.total', 12)
            ->where('quizzes.data.0.quiz_date', today()->toDateString())
            ->where('quizzes.data.9.quiz_date', today()->addDays(9)->toDateString())
            ->where('counts.upcoming', 12)
            ->where('counts.past', 0));

    $this->actingAs($this->admin)
        ->get('/manage/quiz/archive?page=2')
        ->assertInertia(fn (Assert $page) => $page
            ->has('quizzes.data', 2)
            ->where('quizzes.data.0.quiz_date', today()->addDays(10)->toDateString()));
});

it('lists past questions in the archive, most recent first', function () {
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDays(3)]);
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()->subDay()]);
    DailyQuiz::factory()->create(['quiz_date' => today()->addDay()]);

    $this->actingAs($this->admin)
        ->get('/manage/quiz/archive?scope=past')
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'past')
            ->has('quizzes.data', 2)
            ->where('quizzes.data.0.quiz_date', today()->subDay()->toDateString())
            ->where('quizzes.data.1.quiz_date', today()->subDays(3)->toDateString())
            ->where('counts.upcoming', 1)
            ->where('counts.past', 2));
});

it('treats an unknown archive scope as the upcoming queue', function () {
    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->get('/manage/quiz/archive?scope=nonsense')
        ->assertInertia(fn (Assert $page) => $page->where('scope', 'upcoming')->has('quizzes.data', 1));
});

it('redirects guests away from the archive', function () {
    $this->get('/manage/quiz/archive')->assertRedirect('/manage/login');
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
            'question' => '<p dir="rtl">سؤال معدّل؟</p><pre dir="ltr"><code>x = 1</code></pre>',
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
        ->and($quiz->question)->toBe('<p dir="rtl">سؤال معدّل؟</p><pre dir="ltr"><code>x = 1</code></pre>')
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
    'long question' => [['question' => str_repeat('س', 1201)], 'question'],
    'empty question' => [['question' => ''], 'question'],
    'long option' => [['options' => [str_repeat('س', 101), 'ب', 'ج', 'د']], 'options.0'],
    'three options' => [['options' => ['أ', 'ب', 'ج']], 'options'],
    'duplicate options' => [['options' => ['أ', 'أ', 'ج', 'د']], 'options.0'],
    'blank option' => [['options' => ['أ', '   ', 'ج', 'د']], 'options.1'],
    'long explanation' => [['explanation' => str_repeat('س', 201)], 'explanation'],
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

    Queue::assertPushed(GenerateDailyQuizJob::class, fn (GenerateDailyQuizJob $job): bool => $job->queue === 'ai'
        && $job->date === today()->toDateString()
        && $job->replace === false);
});

it('queues generation for a future date to fill the buffer', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->post('/manage/quiz/generate', ['date' => today()->addDays(4)->toDateString()])
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(GenerateDailyQuizJob::class, fn (GenerateDailyQuizJob $job): bool => $job->date === today()->addDays(4)->toDateString()
        && $job->replace === false);
});

it('refuses to generate for a day that has already gone', function () {
    Queue::fake();

    $this->actingAs($this->admin)
        ->post('/manage/quiz/generate', ['date' => today()->subDay()->toDateString()])
        ->assertSessionHasErrors('date');

    Queue::assertNothingPushed();
});

it('asks to replace rather than overwrite when the target day is already taken', function () {
    Queue::fake();
    DailyQuiz::factory()->create(['quiz_date' => today()->addDays(2)]);

    $this->actingAs($this->admin)
        ->post('/manage/quiz/generate', ['date' => today()->addDays(2)->toDateString()])
        ->assertRedirect()
        ->assertSessionHas('success');

    Queue::assertPushed(GenerateDailyQuizJob::class, fn (GenerateDailyQuizJob $job): bool => $job->date === today()->addDays(2)->toDateString()
        && $job->replace === true);
});

it('refuses to regenerate a posted question on a future-dated day', function () {
    Queue::fake();
    DailyQuiz::factory()->posted()->create(['quiz_date' => today()->addDay()]);

    $this->actingAs($this->admin)
        ->post('/manage/quiz/generate', ['date' => today()->addDay()->toDateString()])
        ->assertSessionHasErrors('generate');

    Queue::assertNothingPushed();
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

/** Turn the feature on with one target group — the state on-demand posting needs. */
function configuredQuizGroups(): void
{
    $settings = app(QuizSettings::class);
    $settings->enabled = true;
    $settings->chat_ids = ['-100200300'];
    $settings->save();
}

it('exposes the posting schedule on the page', function () {
    DailyQuiz::factory()->create(['quiz_date' => today(), 'post_time' => '19:30']);

    $this->actingAs($this->admin)
        ->get('/manage/quiz')
        ->assertInertia(fn (Assert $page) => $page
            ->where('schedule.post_time', QuizSchedule::DEFAULT_POST_TIME)
            ->where('schedule.today_post_time', '19:30')
            ->has('schedule.today_posts_at')
            ->where('currentQuiz.post_time', '19:30'));
});

it('moves the default posting time for every day', function () {
    $this->actingAs($this->admin)
        ->put('/manage/quiz/schedule', ['scope' => 'default', 'post_time' => '18:45'])
        ->assertRedirect();

    expect(app(QuizSettings::class)->post_time)->toBe('18:45');
});

it('moves today\'s posting time only, leaving the default alone', function () {
    $quiz = DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->put('/manage/quiz/schedule', ['scope' => 'today', 'post_time' => '20:00'])
        ->assertRedirect();

    expect($quiz->refresh()->post_time)->toBe('20:00')
        ->and(app(QuizSettings::class)->post_time)->toBe(QuizSchedule::DEFAULT_POST_TIME);
});

it('clears today\'s one-day time and returns it to the default', function () {
    $quiz = DailyQuiz::factory()->create(['quiz_date' => today(), 'post_time' => '20:00']);

    $this->actingAs($this->admin)
        ->put('/manage/quiz/schedule', ['scope' => 'today', 'post_time' => null])
        ->assertRedirect();

    expect($quiz->refresh()->post_time)->toBeNull();
});

it('rejects a malformed posting time', function () {
    $this->actingAs($this->admin)
        ->put('/manage/quiz/schedule', ['scope' => 'default', 'post_time' => '25:99'])
        ->assertSessionHasErrors('post_time');

    expect(app(QuizSettings::class)->post_time)->toBe(QuizSchedule::DEFAULT_POST_TIME);
});

it('refuses to reschedule a question that already went out', function () {
    DailyQuiz::factory()->posted()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->put('/manage/quiz/schedule', ['scope' => 'today', 'post_time' => '20:00'])
        ->assertSessionHasErrors('post_time');
});

it('posts today\'s question on demand', function () {
    configuredQuizGroups();

    $fake = new FakeTelegramApi;
    $this->app->bind(QuizPoster::class, fn (): QuizPoster => new QuizPoster(app(QuizSettings::class), $fake));

    $quiz = DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->post('/manage/quiz/post')
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($fake->sentPolls)->toHaveCount(1)
        ->and($quiz->refresh()->status)->toBe(DailyQuiz::STATUS_POSTED);
});

it('re-posts a question whose poll was deleted, keeping its answers', function () {
    configuredQuizGroups();

    $fake = new FakeTelegramApi;
    $this->app->bind(QuizPoster::class, fn (): QuizPoster => new QuizPoster(app(QuizSettings::class), $fake));

    $quiz = DailyQuiz::factory()->posted()->create(['quiz_date' => today()]);
    QuizAnswer::factory()->count(2)->create(['daily_quiz_id' => $quiz->id]);

    $this->actingAs($this->admin)
        ->post('/manage/quiz/post')
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($fake->sentPolls)->toHaveCount(1)
        ->and($quiz->refresh()->status)->toBe(DailyQuiz::STATUS_POSTED)
        ->and($quiz->answers()->count())->toBe(2)
        ->and($quiz->posts()->open()->count())->toBe(1);
});

it('refuses to post on demand while the quiz is not configured', function () {
    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->post('/manage/quiz/post')
        ->assertSessionHasErrors(['post' => 'سؤال اليوم غير مفعّل أو بلا مجموعات مستهدفة.']);
});

it('refuses to post on demand when today has no question yet', function () {
    configuredQuizGroups();

    $this->actingAs($this->admin)
        ->post('/manage/quiz/post')
        ->assertSessionHasErrors(['post' => 'لا يوجد سؤال لهذا اليوم — ولّده أولاً.']);
});

it('refuses to post on demand once the question has closed', function () {
    configuredQuizGroups();
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()]);

    $this->actingAs($this->admin)
        ->post('/manage/quiz/post')
        ->assertSessionHasErrors(['post' => 'سؤال اليوم أُغلق ولا يمكن نشره من جديد.']);
});
