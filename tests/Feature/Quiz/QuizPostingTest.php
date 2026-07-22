<?php

use App\Ai\Quiz\QuizAuthoringAgent;
use App\Models\DailyQuiz;
use App\Models\QuizPost;
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
    $settings->chat_ids = ['-100200300'];
    $settings->save();

    $this->fake = new FakeTelegramApi;
    $this->app->bind(QuizPoster::class, fn (): QuizPoster => new QuizPoster(app(QuizSettings::class), $this->fake));
});

/**
 * A quiz that is live in the group: status posted + one open QuizPost with
 * known Telegram identifiers.
 */
function livePostedQuiz(array $quizAttributes = [], array $postAttributes = []): DailyQuiz
{
    $quiz = DailyQuiz::factory()->create([
        'status' => DailyQuiz::STATUS_POSTED,
        'posted_at' => now(),
        ...$quizAttributes,
    ]);

    QuizPost::factory()->create(['daily_quiz_id' => $quiz->id, ...$postAttributes]);

    return $quiz;
}

it('posts today\'s ready quiz as a non-anonymous quiz poll and records the post', function () {
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
    $post = $quiz->posts()->first();

    expect($quiz->status)->toBe(DailyQuiz::STATUS_POSTED)
        ->and($quiz->posted_at)->not->toBeNull()
        ->and($post)->not->toBeNull()
        ->and($post->telegram_poll_id)->not->toBeNull()
        ->and($post->chat_id)->toBe(-100200300)
        ->and($post->closed_at)->toBeNull();
});

it('posts to every configured group and pins each post', function () {
    $settings = app(QuizSettings::class);
    $settings->chat_ids = ['-100200300', '-100400500'];
    $settings->save();

    $quiz = DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls)->toHaveCount(2)
        ->and(collect($this->fake->sentPolls)->pluck('chat_id')->all())->toBe([-100200300, -100400500])
        ->and($quiz->posts()->count())->toBe(2)
        ->and($quiz->posts()->pluck('telegram_poll_id')->unique())->toHaveCount(2)
        ->and($this->fake->pinnedMessages)->toHaveCount(2)
        ->and($quiz->refresh()->status)->toBe(DailyQuiz::STATUS_POSTED);
});

it('keeps posting to the remaining groups when one group fails', function () {
    $flaky = new class extends FakeTelegramApi
    {
        public function sendPoll(array $params): \Telegram\Bot\Objects\Message
        {
            if ($params['chat_id'] === -100200300) {
                throw new RuntimeException('Forbidden: bot was kicked from the supergroup chat');
            }

            return parent::sendPoll($params);
        }
    };

    $this->app->bind(QuizPoster::class, fn (): QuizPoster => new QuizPoster(app(QuizSettings::class), $flaky));

    $settings = app(QuizSettings::class);
    $settings->chat_ids = ['-100200300', '-100400500'];
    $settings->save();

    $quiz = DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($quiz->posts()->count())->toBe(1)
        ->and($quiz->posts()->first()->chat_id)->toBe(-100400500)
        ->and($quiz->refresh()->status)->toBe(DailyQuiz::STATUS_POSTED);
});

it('fails when every configured group rejects the poll', function () {
    $broken = new class extends FakeTelegramApi
    {
        public function sendPoll(array $params): \Telegram\Bot\Objects\Message
        {
            throw new RuntimeException('Forbidden');
        }
    };

    $this->app->bind(QuizPoster::class, fn (): QuizPoster => new QuizPoster(app(QuizSettings::class), $broken));

    $quiz = DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(1);

    expect($quiz->refresh()->status)->toBe(DailyQuiz::STATUS_READY);
});

it('stops and unpins the previous open polls in every group before posting', function () {
    $previous = livePostedQuiz(
        ['quiz_date' => today()->subDay()],
        ['chat_id' => -100200300, 'message_id' => 777],
    );
    QuizPost::factory()->create(['daily_quiz_id' => $previous->id, 'chat_id' => -100400500, 'message_id' => 888]);

    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect(collect($this->fake->stoppedPolls)->pluck('message_id')->sort()->values()->all())->toBe([777, 888])
        ->and(collect($this->fake->unpinnedMessages)->pluck('message_id')->sort()->values()->all())->toBe([777, 888])
        ->and($previous->refresh()->status)->toBe(DailyQuiz::STATUS_CLOSED)
        ->and($previous->posts()->open()->count())->toBe(0);
});

it('pins the new quiz quietly', function () {
    $quiz = DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->pinnedMessages)->toHaveCount(1)
        ->and($this->fake->pinnedMessages[0]['chat_id'])->toBe(-100200300)
        ->and($this->fake->pinnedMessages[0]['message_id'])->toBe($quiz->posts()->first()->message_id)
        ->and($this->fake->pinnedMessages[0]['disable_notification'])->toBeTrue();
});

it('still posts when pinning is not permitted', function () {
    $noPinRights = new class extends FakeTelegramApi
    {
        public function pinChatMessage(array $params): bool
        {
            throw new RuntimeException('Bad Request: not enough rights to manage pinned messages');
        }
    };

    $this->app->bind(QuizPoster::class, fn (): QuizPoster => new QuizPoster(app(QuizSettings::class), $noPinRights));

    $quiz = DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($noPinRights->sentPolls)->toHaveCount(1)
        ->and($quiz->refresh()->status)->toBe(DailyQuiz::STATUS_POSTED);
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

    $previous = livePostedQuiz(['quiz_date' => today()->subDay()]);
    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($previous->refresh()->status)->toBe(DailyQuiz::STATUS_CLOSED)
        ->and($failing->sentPolls)->toHaveCount(1);
});

it('skips while the feature is disabled or has no target groups', function (bool $enabled, array $chatIds) {
    $settings = app(QuizSettings::class);
    $settings->enabled = $enabled;
    $settings->chat_ids = $chatIds;
    $settings->save();

    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls)->toBeEmpty();
})->with([
    'disabled' => [false, ['-100200300']],
    'no groups' => [true, []],
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
    livePostedQuiz(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls)->toBeEmpty()
        ->and($this->fake->stoppedPolls)->toBeEmpty();
});

it('omits the explanation parameter when the quiz has none', function () {
    DailyQuiz::factory()->create(['quiz_date' => today(), 'explanation' => null]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls[0])->not->toHaveKey('explanation');
});
