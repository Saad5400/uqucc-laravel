<?php

use App\Ai\Quiz\QuizAuthoringAgent;
use App\Models\DailyQuiz;
use App\Models\QuizTopic;
use App\Settings\AiSettings;
use App\Settings\QuizSettings;
use Carbon\CarbonInterface;

beforeEach(function () {
    config()->set('ai.providers.openrouter.key', 'test-key');

    $ai = app(AiSettings::class);
    $ai->ai_enabled = true;
    $ai->daily_budget_usd = 5.0;
    $ai->save();

    $quiz = app(QuizSettings::class);
    $quiz->enabled = true;
    $quiz->chat_id = '-100200300';
    $quiz->save();
});

function quizJson(array $overrides = []): string
{
    return json_encode([
        'question' => 'ما البوابة المنطقية التي تعكس قيمة المدخل؟',
        'options' => ['AND', 'OR', 'NOT', 'XOR'],
        'correct_option' => 2,
        'explanation' => 'بوابة NOT تُخرج عكس قيمة المدخل دائماً.',
        ...$overrides,
    ], JSON_UNESCAPED_UNICODE);
}

it('generates a ready quiz from the least-recently-used active topic', function () {
    $stale = QuizTopic::factory()->create(['name' => 'قواعد البيانات', 'last_used_at' => now()->subDays(2)]);
    $neverUsed = QuizTopic::factory()->create(['name' => 'الشبكات', 'last_used_at' => null]);

    QuizAuthoringAgent::fake([quizJson()]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    $quiz = DailyQuiz::forDate(today());

    expect($quiz)->not->toBeNull()
        ->and($quiz->status)->toBe(DailyQuiz::STATUS_READY)
        ->and($quiz->quiz_topic_id)->toBe($neverUsed->id)
        ->and($quiz->question)->toBe('ما البوابة المنطقية التي تعكس قيمة المدخل؟')
        ->and($quiz->options)->toBe(['AND', 'OR', 'NOT', 'XOR'])
        ->and($quiz->correct_option)->toBe(2)
        ->and($quiz->explanation)->not->toBeNull()
        ->and($neverUsed->refresh()->last_used_at)->not->toBeNull()
        ->and($stale->refresh()->last_used_at->isSameDay(now()->subDays(2)))->toBeTrue();
});

it('skips silently while the quiz feature is disabled', function () {
    $settings = app(QuizSettings::class);
    $settings->enabled = false;
    $settings->save();

    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake([quizJson()]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    expect(DailyQuiz::query()->count())->toBe(0);
    QuizAuthoringAgent::assertNeverPrompted();
});

it('skips when a quiz already exists for the day', function () {
    QuizTopic::factory()->create();
    DailyQuiz::factory()->create(['quiz_date' => today()]);
    QuizAuthoringAgent::fake([quizJson()]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    expect(DailyQuiz::query()->count())->toBe(1);
    QuizAuthoringAgent::assertNeverPrompted();
});

it('fails when no active topics exist', function () {
    QuizTopic::factory()->inactive()->create();
    QuizAuthoringAgent::fake([quizJson()]);

    $this->artisan('quiz:generate')->assertExitCode(1);

    expect(DailyQuiz::query()->count())->toBe(0);
});

it('fails while the AI master switch is off', function () {
    $ai = app(AiSettings::class);
    $ai->ai_enabled = false;
    $ai->save();

    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake([quizJson()]);

    $this->artisan('quiz:generate')->assertExitCode(1);

    expect(DailyQuiz::query()->count())->toBe(0);
    QuizAuthoringAgent::assertNeverPrompted();
});

it('retries once after an invalid response', function () {
    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake(['ناتج ليس JSON', quizJson()]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    expect(DailyQuiz::query()->count())->toBe(1);
});

it('gives up after two invalid responses', function () {
    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake(['ناتج ليس JSON', 'ولا هذا JSON']);

    $this->artisan('quiz:generate')->assertExitCode(1);

    expect(DailyQuiz::query()->count())->toBe(0);
});

it('rejects a question longer than the Telegram poll limit', function () {
    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake([
        quizJson(['question' => str_repeat('س', 301)]),
        quizJson(['question' => str_repeat('س', 301)]),
    ]);

    $this->artisan('quiz:generate')->assertExitCode(1);

    expect(DailyQuiz::query()->count())->toBe(0);
});

it('rejects duplicated options', function () {
    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake([
        quizJson(['options' => ['AND', 'AND', 'NOT', 'XOR']]),
        quizJson(['options' => ['AND', 'AND', 'NOT', 'XOR']]),
    ]);

    $this->artisan('quiz:generate')->assertExitCode(1);

    expect(DailyQuiz::query()->count())->toBe(0);
});

it('tolerates a markdown code fence around the JSON', function () {
    QuizTopic::factory()->create();
    QuizAuthoringAgent::fake(["```json\n".quizJson()."\n```"]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    expect(DailyQuiz::query()->count())->toBe(1);
});

it('prefers spotlight topics on the spotlight weekday', function () {
    QuizTopic::factory()->create(['name' => 'أساسيات']);
    $spotlight = QuizTopic::factory()->spotlight()->create(['name' => 'أمن سيبراني متقدم']);

    QuizAuthoringAgent::fake([quizJson()]);

    $wednesday = today()->next(CarbonInterface::WEDNESDAY);

    $this->artisan('quiz:generate', ['--date' => $wednesday->toDateString()])->assertExitCode(0);

    expect(DailyQuiz::forDate($wednesday)->quiz_topic_id)->toBe($spotlight->id);
});

it('avoids spotlight topics on regular days while regular topics exist', function () {
    $regular = QuizTopic::factory()->create(['name' => 'أساسيات']);
    QuizTopic::factory()->spotlight()->create(['name' => 'أمن سيبراني متقدم']);

    QuizAuthoringAgent::fake([quizJson()]);

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

    QuizAuthoringAgent::fake([quizJson()]);

    $this->artisan('quiz:generate')->assertExitCode(0);

    QuizAuthoringAgent::assertPrompted(
        fn (Laravel\Ai\Prompts\AgentPrompt $prompt): bool => str_contains((string) $prompt->prompt, 'سؤال الأمس المميز جداً؟'),
    );
});
