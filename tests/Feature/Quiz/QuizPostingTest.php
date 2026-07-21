<?php

use App\Ai\Quiz\QuizAuthoringAgent;
use App\Models\DailyQuiz;
use App\Models\QuizTopic;
use App\Services\Quiz\QuizPoster;
use App\Settings\AiSettings;
use App\Settings\QuizSettings;
use Tests\Fakes\FakeTelegramApi;

beforeEach(function () {
    config()->set('ai.providers.openrouter.key', 'test-key');

    $ai = app(AiSettings::class);
    $ai->ai_enabled = true;
    $ai->daily_budget_usd = 5.0;
    $ai->save();

    $settings = app(QuizSettings::class);
    $settings->enabled = true;
    $settings->chat_id = '-100200300';
    $settings->save();

    $this->fake = new FakeTelegramApi;
    $this->app->bind(QuizPoster::class, fn (): QuizPoster => new QuizPoster(app(QuizSettings::class), $this->fake));
});

it('posts today\'s ready quiz as a non-anonymous quiz poll', function () {
    $quiz = DailyQuiz::factory()->create([
        'quiz_date' => today(),
        'question' => 'ما ناتج 1 + 1؟',
        'options' => ['1', '2', '3', '4'],
        'correct_option' => 1,
        'explanation' => 'جمع بسيط.',
    ]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls)->toHaveCount(1);

    $params = $this->fake->sentPolls[0];

    expect($params['chat_id'])->toBe(-100200300)
        ->and($params['question'])->toBe('ما ناتج 1 + 1؟')
        ->and($params['options'])->toBe(['1', '2', '3', '4'])
        ->and($params['type'])->toBe('quiz')
        ->and($params['is_anonymous'])->toBeFalse()
        ->and($params['correct_option_id'])->toBe(1)
        ->and($params['explanation'])->toBe('جمع بسيط.');

    $quiz->refresh();

    expect($quiz->status)->toBe(DailyQuiz::STATUS_POSTED)
        ->and($quiz->telegram_poll_id)->not->toBeNull()
        ->and($quiz->chat_id)->toBe(-100200300)
        ->and($quiz->message_id)->not->toBeNull()
        ->and($quiz->posted_at)->not->toBeNull();
});

it('stops the previous open poll before posting the new one', function () {
    $previous = DailyQuiz::factory()->posted()->create([
        'quiz_date' => today()->subDay(),
        'message_id' => 777,
    ]);
    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->stoppedPolls)->toHaveCount(1)
        ->and($this->fake->stoppedPolls[0]['message_id'])->toBe(777)
        ->and($previous->refresh()->status)->toBe(DailyQuiz::STATUS_CLOSED)
        ->and($previous->closed_at)->not->toBeNull();
});

it('marks the previous poll closed even when stopping it fails on Telegram', function () {
    $failing = new class extends FakeTelegramApi
    {
        public function stopPoll(array $params): \Telegram\Bot\Objects\Poll
        {
            throw new RuntimeException('Bad Request: poll has already been closed');
        }
    };

    $this->app->bind(QuizPoster::class, fn (): QuizPoster => new QuizPoster(app(QuizSettings::class), $failing));

    $previous = DailyQuiz::factory()->posted()->create(['quiz_date' => today()->subDay()]);
    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($previous->refresh()->status)->toBe(DailyQuiz::STATUS_CLOSED)
        ->and($failing->sentPolls)->toHaveCount(1);
});

it('skips while the feature is disabled or has no target group', function (bool $enabled, ?string $chatId) {
    $settings = app(QuizSettings::class);
    $settings->enabled = $enabled;
    $settings->chat_id = $chatId;
    $settings->save();

    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls)->toBeEmpty();
})->with([
    'disabled' => [false, '-100200300'],
    'no group' => [true, null],
]);

it('generates a quiz inline when the nightly generation left none', function () {
    QuizTopic::factory()->create();

    QuizAuthoringAgent::fake([json_encode([
        'question' => 'سؤال مولّد عند النشر؟',
        'options' => ['أ', 'ب', 'ج', 'د'],
        'correct_option' => 0,
        'explanation' => 'شرح.',
    ], JSON_UNESCAPED_UNICODE)]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls)->toHaveCount(1)
        ->and($this->fake->sentPolls[0]['question'])->toBe('سؤال مولّد عند النشر؟')
        ->and(DailyQuiz::forDate(today())->status)->toBe(DailyQuiz::STATUS_POSTED);
});

it('fails when there is no quiz and fallback generation cannot run', function () {
    $this->artisan('quiz:post')->assertExitCode(1);

    expect($this->fake->sentPolls)->toBeEmpty();
});

it('does nothing when today\'s quiz was already posted', function () {
    DailyQuiz::factory()->posted()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls)->toBeEmpty()
        ->and($this->fake->stoppedPolls)->toBeEmpty();
});

it('omits the explanation parameter when the quiz has none', function () {
    DailyQuiz::factory()->create(['quiz_date' => today(), 'explanation' => null]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls[0])->not->toHaveKey('explanation');
});
