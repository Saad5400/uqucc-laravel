<?php

use App\Ai\Quiz\QuizAuthor;
use App\Ai\Quiz\QuizAuthoringAgent;
use App\Jobs\GenerateDailyQuizJob;
use App\Models\DailyQuiz;
use App\Models\QuizTopic;
use App\Settings\AiSettings;
use App\Settings\QuizSettings;
use Carbon\CarbonInterface;
use Laravel\Ai\Responses\Data\ToolCall;

beforeEach(function () {
    config()->set('ai.providers.openrouter.key', 'test-key');

    $ai = app(AiSettings::class);
    $ai->ai_enabled = true;
    $ai->daily_budget_usd = 5.0;
    $ai->save();

    $quiz = app(QuizSettings::class);
    $quiz->enabled = true;
    $quiz->chat_ids = ['-100200300'];
    $quiz->save();
});

/**
 * A faked model turn that submits a question through the submit_quiz_question
 * tool. The fake gateway runs the REAL tool, so overriding fields here exercises
 * the tool's actual validation.
 */
function quizToolCall(array $overrides = []): ToolCall
{
    return new ToolCall('call_1', 'submit_quiz_question', [
        'question' => 'ما البوابة المنطقية التي تعكس قيمة المدخل؟',
        'body' => '',
        'options' => ['AND', 'OR', 'NOT', 'XOR'],
        'correct_option' => 2,
        'explanation' => 'بوابة NOT تُخرج عكس قيمة المدخل دائماً.',
        'hint' => 'فكّر في العملية التي تقلب القيمة.',
        'obvious_hint' => 'البوابة التي تحوّل 1 إلى 0.',
        ...$overrides,
    ]);
}

it('generates a ready quiz from the least-recently-used active topic', function () {
    $stale = QuizTopic::factory()->create(['name' => 'قواعد البيانات', 'last_used_at' => now()->subDays(2)]);
    $neverUsed = QuizTopic::factory()->create(['name' => 'الشبكات', 'last_used_at' => null]);

    QuizAuthoringAgent::fake([quizToolCall()]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    $quiz = DailyQuiz::forDate(today());

    expect($quiz)->not->toBeNull()
        ->and($quiz->status)->toBe(DailyQuiz::STATUS_READY)
        ->and($quiz->quiz_topic_id)->toBe($neverUsed->id)
        ->and($quiz->question)->toBe('ما البوابة المنطقية التي تعكس قيمة المدخل؟')
        ->and($quiz->options)->toBe(['AND', 'OR', 'NOT', 'XOR'])
        ->and($quiz->correct_option)->toBe(2)
        ->and($quiz->explanation)->not->toBeNull()
        ->and($quiz->hint)->toBe('فكّر في العملية التي تقلب القيمة.')
        ->and($quiz->obvious_hint)->toBe('البوابة التي تحوّل 1 إلى 0.')
        ->and($neverUsed->refresh()->last_used_at)->not->toBeNull()
        ->and($stale->refresh()->last_used_at->isSameDay(now()->subDays(2)))->toBeTrue();
});

it('stores the optional body block from the generated question', function () {
    QuizTopic::factory()->create();

    QuizAuthoringAgent::fake([quizToolCall([
        'question' => 'ماذا يُطبع؟',
        'body' => "في الكود التالي:\n```py\nprint(2 ** 3)\n```",
    ])]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    $quiz = DailyQuiz::forDate(today());

    expect($quiz->question)->toBe('ماذا يُطبع؟')
        ->and($quiz->body)->toBe("في الكود التالي:\n```py\nprint(2 ** 3)\n```");
});

it('leaves the body null when the generated question omits it', function () {
    QuizTopic::factory()->create();

    QuizAuthoringAgent::fake([quizToolCall()]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    expect(DailyQuiz::forDate(today())->body)->toBeNull();
});

it('corrects a too-long body within the same run', function () {
    QuizTopic::factory()->create();

    QuizAuthoringAgent::fake([
        quizToolCall(['body' => str_repeat('ا', 701)]),
        quizToolCall(['question' => 'سؤال مصحّح بعد رفض المقدمة الطويلة؟']),
    ]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    expect(DailyQuiz::query()->count())->toBe(1)
        ->and(DailyQuiz::forDate(today())->question)->toBe('سؤال مصحّح بعد رفض المقدمة الطويلة؟');
});

it('skips silently while the quiz feature is disabled', function () {
    $settings = app(QuizSettings::class);
    $settings->enabled = false;
    $settings->save();

    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake([quizToolCall()]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    expect(DailyQuiz::query()->count())->toBe(0);
    QuizAuthoringAgent::assertNeverPrompted();
});

it('generates from an explicitly chosen topic instead of the auto pick', function () {
    QuizTopic::factory()->create(['name' => 'الأقل استخداماً', 'last_used_at' => null]);
    $chosen = QuizTopic::factory()->create(['name' => 'موضوع مختار', 'last_used_at' => now()]);

    QuizAuthoringAgent::fake([quizToolCall()]);

    $quiz = app(QuizAuthor::class)->generateForDate(today(), $chosen);

    expect($quiz->quiz_topic_id)->toBe($chosen->id)
        ->and($chosen->refresh()->last_used_at->isToday())->toBeTrue();
});

it('replaces a ready quiz in place when regenerating', function () {
    $topic = QuizTopic::factory()->create();
    $old = DailyQuiz::factory()->create(['quiz_date' => today(), 'question' => 'السؤال القديم؟']);

    QuizAuthoringAgent::fake([quizToolCall(['question' => 'السؤال الجديد؟'])]);

    $quiz = app(QuizAuthor::class)->generateForDate(today(), $topic, replace: true);

    expect(DailyQuiz::query()->count())->toBe(1)
        ->and(DailyQuiz::find($old->id))->toBeNull()
        ->and($quiz->question)->toBe('السؤال الجديد؟');
});

it('keeps the existing quiz when a replacement generation fails', function () {
    $topic = QuizTopic::factory()->create();
    $old = DailyQuiz::factory()->create(['quiz_date' => today(), 'question' => 'السؤال القديم؟']);

    QuizAuthoringAgent::fake(['لم أرسل سؤالاً عبر الأداة', 'ولا في هذه المحاولة']);

    expect(fn () => app(QuizAuthor::class)->generateForDate(today(), $topic, replace: true))
        ->toThrow(RuntimeException::class);

    expect(DailyQuiz::query()->count())->toBe(1)
        ->and(DailyQuiz::find($old->id)->question)->toBe('السؤال القديم؟');
});

it('refuses to replace a quiz that is already posted', function () {
    $topic = QuizTopic::factory()->create();
    DailyQuiz::factory()->posted()->create(['quiz_date' => today()]);

    QuizAuthoringAgent::fake([quizToolCall()]);

    expect(fn () => app(QuizAuthor::class)->generateForDate(today(), $topic, replace: true))
        ->toThrow(RuntimeException::class);

    QuizAuthoringAgent::assertNeverPrompted();
});

it('generates for a future day when the panel job carries a date', function () {
    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake([quizToolCall(['question' => 'سؤال بعد ثلاثة أيام؟'])]);

    (new GenerateDailyQuizJob(today()->addDays(3)->toDateString()))->handle(app(QuizAuthor::class));

    expect(DailyQuiz::forDate(today()))->toBeNull()
        ->and(DailyQuiz::forDate(today()->addDays(3))?->question)->toBe('سؤال بعد ثلاثة أيام؟');
});

it('never overwrites an existing day unless the job says to replace it', function () {
    QuizTopic::factory()->create();
    $existing = DailyQuiz::factory()->create(['quiz_date' => today()->addDay(), 'question' => 'السؤال المحجوز؟']);

    QuizAuthoringAgent::fake([quizToolCall(['question' => 'سؤال دخيل؟'])]);

    (new GenerateDailyQuizJob(today()->addDay()->toDateString()))->handle(app(QuizAuthor::class));

    expect(DailyQuiz::query()->count())->toBe(1)
        ->and($existing->refresh()->question)->toBe('السؤال المحجوز؟');
    QuizAuthoringAgent::assertNeverPrompted();
});

it('re-rolls a future day when the panel job asks to replace it', function () {
    QuizTopic::factory()->create();
    DailyQuiz::factory()->create(['quiz_date' => today()->addDay(), 'question' => 'السؤال القديم؟']);

    QuizAuthoringAgent::fake([quizToolCall(['question' => 'السؤال الجديد؟'])]);

    (new GenerateDailyQuizJob(today()->addDay()->toDateString(), replace: true))->handle(app(QuizAuthor::class));

    expect(DailyQuiz::query()->count())->toBe(1)
        ->and(DailyQuiz::forDate(today()->addDay())->question)->toBe('السؤال الجديد؟');
});

it('defaults the panel job to today when no date is given', function () {
    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake([quizToolCall()]);

    (new GenerateDailyQuizJob)->handle(app(QuizAuthor::class));

    expect(DailyQuiz::forDate(today()))->not->toBeNull();
});

it('skips when a quiz already exists for the day', function () {
    QuizTopic::factory()->create();
    DailyQuiz::factory()->create(['quiz_date' => today()]);
    QuizAuthoringAgent::fake([quizToolCall()]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    expect(DailyQuiz::query()->count())->toBe(1);
    QuizAuthoringAgent::assertNeverPrompted();
});

it('fails when no active topics exist', function () {
    QuizTopic::factory()->inactive()->create();
    QuizAuthoringAgent::fake([quizToolCall()]);

    $this->artisan('quiz:generate')->assertExitCode(1);

    expect(DailyQuiz::query()->count())->toBe(0);
});

it('fails while the AI master switch is off', function () {
    $ai = app(AiSettings::class);
    $ai->ai_enabled = false;
    $ai->save();

    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake([quizToolCall()]);

    $this->artisan('quiz:generate')->assertExitCode(1);

    expect(DailyQuiz::query()->count())->toBe(0);
    QuizAuthoringAgent::assertNeverPrompted();
});

it('retries when the model finishes without submitting, then succeeds', function () {
    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake(['لم أُرسل سؤالاً بعد', quizToolCall()]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    expect(DailyQuiz::query()->count())->toBe(1);
});

it('gives up when the model never submits a question', function () {
    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake(['نص بلا استدعاء أداة', 'وكذلك هنا']);

    $this->artisan('quiz:generate')->assertExitCode(1);

    expect(DailyQuiz::query()->count())->toBe(0);
});

it('corrects a too-long question within the same run', function () {
    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake([
        quizToolCall(['question' => str_repeat('س', 301)]),
        quizToolCall(['question' => 'سؤال قصير مصحّح؟']),
    ]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    expect(DailyQuiz::query()->count())->toBe(1)
        ->and(DailyQuiz::forDate(today())->question)->toBe('سؤال قصير مصحّح؟');
});

it('corrects duplicated options within the same run', function () {
    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake([
        quizToolCall(['options' => ['AND', 'AND', 'NOT', 'XOR']]),
        quizToolCall(),
    ]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    expect(DailyQuiz::query()->count())->toBe(1)
        ->and(DailyQuiz::forDate(today())->options)->toBe(['AND', 'OR', 'NOT', 'XOR']);
});

it('gives up when the correct option stays much longer than the distractors', function () {
    QuizTopic::factory()->create();
    $lopsided = quizToolCall([
        'options' => ['AND', 'OR', str_repeat('ن', 20), 'XOR'],
        'correct_option' => 2,
    ]);
    QuizAuthoringAgent::fake([$lopsided, $lopsided]);

    $this->artisan('quiz:generate')->assertExitCode(1);

    expect(DailyQuiz::query()->count())->toBe(0);
});

it('corrects a lopsided correct option within the same run', function () {
    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake([
        quizToolCall(['options' => ['AND', 'OR', str_repeat('ن', 20), 'XOR'], 'correct_option' => 2]),
        quizToolCall(),
    ]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    expect(DailyQuiz::query()->count())->toBe(1)
        ->and(DailyQuiz::forDate(today())->options)->toBe(['AND', 'OR', 'NOT', 'XOR']);
});

it('ignores a trailing assistant message after the question is accepted', function () {
    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake([quizToolCall(), 'تم، اعتمدت السؤال.']);

    $this->artisan('quiz:generate')->assertExitCode(0);

    expect(DailyQuiz::query()->count())->toBe(1);
});

it('prefers spotlight topics on the spotlight weekday', function () {
    QuizTopic::factory()->create(['name' => 'أساسيات']);
    $spotlight = QuizTopic::factory()->spotlight()->create(['name' => 'أمن سيبراني متقدم']);

    QuizAuthoringAgent::fake([quizToolCall()]);

    $wednesday = today()->next(CarbonInterface::WEDNESDAY);

    $this->artisan('quiz:generate', ['--date' => $wednesday->toDateString()])->assertExitCode(0);

    expect(DailyQuiz::forDate($wednesday)->quiz_topic_id)->toBe($spotlight->id);
});

it('avoids spotlight topics on regular days while regular topics exist', function () {
    $regular = QuizTopic::factory()->create(['name' => 'أساسيات']);
    QuizTopic::factory()->spotlight()->create(['name' => 'أمن سيبراني متقدم']);

    QuizAuthoringAgent::fake([quizToolCall()]);

    $sunday = today()->next(CarbonInterface::SUNDAY);

    $this->artisan('quiz:generate', ['--date' => $sunday->toDateString()])->assertExitCode(0);

    expect(DailyQuiz::forDate($sunday)->quiz_topic_id)->toBe($regular->id);
});

it('lists recent questions in the prompt so the model avoids repeats', function () {
    QuizTopic::factory()->create();
    DailyQuiz::factory()->create([
        'quiz_date' => today()->subDay(),
        'question' => 'سؤال الأمس المميز جداً؟',
        'status' => DailyQuiz::STATUS_CLOSED,
    ]);

    QuizAuthoringAgent::fake([quizToolCall()]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    QuizAuthoringAgent::assertPrompted(
        fn (Laravel\Ai\Prompts\AgentPrompt $prompt): bool => str_contains((string) $prompt->prompt, 'سؤال الأمس المميز جداً؟'),
    );
});
