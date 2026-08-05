<?php

use App\Ai\Quiz\QuizAuthoringAgent;
use App\Models\DailyQuiz;
use App\Models\QuizAnswer;
use App\Models\QuizPlayer;
use App\Models\QuizPost;
use App\Models\QuizTopic;
use App\Services\Quiz\QuizPoster;
use App\Services\Quiz\QuizSchedule;
use App\Settings\AiSettings;
use App\Settings\QuizSettings;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fakes\FakeTelegramApi;

beforeEach(function () {
    // The command runs every minute and posts only once its moment arrives;
    // sit on the default posting time so the clock is never the reason a test
    // sees nothing. Cache backs the fallback-generation throttle.
    $this->travelTo(today()->setTimeFromTimeString(QuizSchedule::DEFAULT_POST_TIME));
    Cache::flush();

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

it('posts a quiz without a body as a poll only, with the question as the poll question', function () {
    DailyQuiz::factory()->create([
        'quiz_date' => today(),
        'question' => 'ما ناتج 1 + 1؟',
        'body' => null,
    ]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls)->toHaveCount(1)
        ->and($this->fake->sentPolls[0]['question'])->toBe('ما ناتج 1 + 1؟')
        ->and($this->fake->sentMessages)->toBeEmpty();
});

it('sends the body as a formatted HTML message above the poll and keeps the question on the poll', function () {
    DailyQuiz::factory()->withCode()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(1);

    $content = $this->fake->sentMessages[0];

    expect($content['chat_id'])->toBe(-100200300)
        ->and($content['parse_mode'])->toBe('HTML')
        ->and($content['text'])->toContain('<pre>print(2 ** 3)</pre>')
        ->and($content['text'])->not->toContain('ماذا يُطبع؟')
        ->and($content['text'])->not->toContain('```');

    expect($this->fake->sentPolls)->toHaveCount(1)
        ->and($this->fake->sentPolls[0]['question'])->toBe('ماذا يُطبع؟');
});

it('does not post a contextless poll when the body message fails to send', function () {
    $this->fake = new class extends FakeTelegramApi
    {
        public function sendMessage(array $params): \Telegram\Bot\Objects\Message
        {
            throw new \RuntimeException('body send failed');
        }
    };
    $this->app->bind(QuizPoster::class, fn (): QuizPoster => new QuizPoster(app(QuizSettings::class), $this->fake));

    DailyQuiz::factory()->withCode()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(1);

    expect($this->fake->sentPolls)->toBeEmpty();
});

it('posts to every configured group', function () {
    $settings = app(QuizSettings::class);
    $settings->chat_ids = ['-100200300', '-100400500'];
    $settings->save();

    $quiz = DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls)->toHaveCount(2)
        ->and(collect($this->fake->sentPolls)->pluck('chat_id')->all())->toBe([-100200300, -100400500])
        ->and($quiz->posts()->count())->toBe(2)
        ->and($quiz->posts()->pluck('telegram_poll_id')->unique())->toHaveCount(2)
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

it('stops the previous open polls in every group before posting', function () {
    $previous = livePostedQuiz(
        ['quiz_date' => today()->subDay()],
        ['chat_id' => -100200300, 'message_id' => 777],
    );
    QuizPost::factory()->create(['daily_quiz_id' => $previous->id, 'chat_id' => -100400500, 'message_id' => 888]);

    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect(collect($this->fake->stoppedPolls)->pluck('message_id')->sort()->values()->all())->toBe([777, 888])
        ->and($previous->refresh()->status)->toBe(DailyQuiz::STATUS_CLOSED)
        ->and($previous->posts()->open()->count())->toBe(0);
});

it('replies to the previous poll with a recap of how it went', function () {
    $previous = livePostedQuiz(['quiz_date' => today()->subDay()], ['message_id' => 555]);

    $players = QuizPlayer::factory()->count(3)->create();
    QuizAnswer::factory()->create(['daily_quiz_id' => $previous->id, 'quiz_player_id' => $players[0]->id, 'is_correct' => true, 'streak_at_answer' => 4]);
    QuizAnswer::factory()->create(['daily_quiz_id' => $previous->id, 'quiz_player_id' => $players[1]->id, 'is_correct' => true, 'streak_at_answer' => 1]);
    QuizAnswer::factory()->create(['daily_quiz_id' => $previous->id, 'quiz_player_id' => $players[2]->id, 'is_correct' => false, 'streak_at_answer' => 1]);

    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    $recap = collect($this->fake->sentMessages)->firstWhere('reply_to_message_id', 555);

    expect($recap)->not->toBeNull()
        ->and($recap['text'])->toContain('خلاصة سؤال اليوم')
        ->and($recap['text'])->toContain('2 من 3')
        ->and($recap['text'])->toContain('أطول سلسلة')
        ->and($recap['text'])->toContain('4 أيام');
});

it('sends no recap when the previous quiz had no answers', function () {
    livePostedQuiz(['quiz_date' => today()->subDay()]);
    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentMessages)->toBeEmpty();
});

it('posts into a forum topic when a target specifies one', function () {
    $settings = app(QuizSettings::class);
    $settings->chat_ids = ['-100200300:42'];
    $settings->save();

    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls[0]['message_thread_id'])->toBe(42)
        ->and(DailyQuiz::forDate(today())->posts()->first()->message_thread_id)->toBe(42);
});

it('never touches the group\'s pinned messages', function () {
    livePostedQuiz(['quiz_date' => today()->subDay()]);
    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->pinnedMessages)->toBeEmpty()
        ->and($this->fake->unpinnedMessages)->toBeEmpty();
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

    QuizAuthoringAgent::fake([new ToolCall('call_1', 'submit_quiz_question', [
        'question' => 'سؤال مولّد عند النشر؟',
        'options' => ['أ', 'ب', 'ج', 'د'],
        'correct_option' => 0,
        'explanation' => 'شرح.',
    ])]);

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

it('waits for the configured posting time before going out', function () {
    $settings = app(QuizSettings::class);
    $settings->post_time = '18:00';
    $settings->save();

    DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls)->toBeEmpty();

    $this->travelTo(today()->setTime(18, 0));

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls)->toHaveCount(1)
        ->and(DailyQuiz::forDate(today())->status)->toBe(DailyQuiz::STATUS_POSTED);
});

it('honours a one-day posting time without touching the default', function () {
    $quiz = DailyQuiz::factory()->create(['quiz_date' => today(), 'post_time' => '20:30']);

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls)->toBeEmpty();

    $this->travelTo(today()->setTime(20, 30));

    $this->artisan('quiz:post')->assertExitCode(0);

    expect($this->fake->sentPolls)->toHaveCount(1)
        ->and($quiz->refresh()->status)->toBe(DailyQuiz::STATUS_POSTED)
        ->and(app(QuizSettings::class)->post_time)->toBe(QuizSchedule::DEFAULT_POST_TIME);
});

it('posts immediately with --force, ahead of the scheduled time', function () {
    $this->travelTo(today()->setTime(9, 0));

    $quiz = DailyQuiz::factory()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post', ['--force' => true])->assertExitCode(0);

    expect($this->fake->sentPolls)->toHaveCount(1)
        ->and($quiz->refresh()->status)->toBe(DailyQuiz::STATUS_POSTED);
});

it('re-posts an already posted question with --force, keeping its recorded answers', function () {
    $quiz = livePostedQuiz(['quiz_date' => today()], ['message_id' => 999]);
    QuizAnswer::factory()->count(3)->create(['daily_quiz_id' => $quiz->id]);

    $this->artisan('quiz:post', ['--force' => true])->assertExitCode(0);

    $quiz->refresh();

    expect($this->fake->sentPolls)->toHaveCount(1)
        ->and($this->fake->stoppedPolls)->toHaveCount(1)
        ->and($this->fake->stoppedPolls[0]['message_id'])->toBe(999)
        ->and($quiz->status)->toBe(DailyQuiz::STATUS_POSTED)
        ->and($quiz->answers()->count())->toBe(3)
        // One row per group: the re-post moves it onto the new poll.
        ->and($quiz->posts()->count())->toBe(1)
        ->and($quiz->posts()->open()->count())->toBe(1)
        ->and($quiz->posts()->first()->message_id)->not->toBe(999)
        ->and($quiz->posts()->first()->telegram_poll_id)->not->toBeNull()
        ->and(DailyQuiz::query()->count())->toBe(1);
});

it('keeps answers landing on the same quiz after a re-post', function () {
    $quiz = livePostedQuiz(['quiz_date' => today()]);

    $this->artisan('quiz:post', ['--force' => true])->assertExitCode(0);

    expect(DailyQuiz::findByPollId($quiz->posts()->first()->telegram_poll_id)?->id)->toBe($quiz->id);
});

it('sends no recap when re-posting, because the day is not over', function () {
    $quiz = livePostedQuiz(['quiz_date' => today()], ['message_id' => 999]);
    QuizAnswer::factory()->count(3)->create(['daily_quiz_id' => $quiz->id]);

    $this->artisan('quiz:post', ['--force' => true])->assertExitCode(0);

    expect(collect($this->fake->sentMessages)->pluck('reply_to_message_id')->filter())->toBeEmpty();
});

it('still recaps and closes the previous day when re-posting today', function () {
    $yesterday = livePostedQuiz(['quiz_date' => today()->subDay()], ['message_id' => 555]);
    QuizAnswer::factory()->create(['daily_quiz_id' => $yesterday->id]);

    livePostedQuiz(['quiz_date' => today()], ['message_id' => 999]);

    $this->artisan('quiz:post', ['--force' => true])->assertExitCode(0);

    expect(collect($this->fake->sentMessages)->firstWhere('reply_to_message_id', 555))->not->toBeNull()
        ->and($yesterday->refresh()->status)->toBe(DailyQuiz::STATUS_CLOSED)
        ->and(DailyQuiz::forDate(today())->status)->toBe(DailyQuiz::STATUS_POSTED);
});

it('fails a re-post when every group rejects it, leaving the old post closed', function () {
    $broken = new class extends FakeTelegramApi
    {
        public function sendPoll(array $params): \Telegram\Bot\Objects\Message
        {
            throw new RuntimeException('Forbidden');
        }
    };

    $this->app->bind(QuizPoster::class, fn (): QuizPoster => new QuizPoster(app(QuizSettings::class), $broken));

    $quiz = livePostedQuiz(['quiz_date' => today()]);

    $this->artisan('quiz:post', ['--force' => true])->assertExitCode(1);

    expect($quiz->refresh()->status)->toBe(DailyQuiz::STATUS_POSTED)
        ->and($quiz->posts()->open()->count())->toBe(0);
});

it('refuses to force-post a closed question', function () {
    DailyQuiz::factory()->closed()->create(['quiz_date' => today()]);

    $this->artisan('quiz:post', ['--force' => true])->assertExitCode(1);

    expect($this->fake->sentPolls)->toBeEmpty();
});

it('refuses to force-post when today has no question at all', function () {
    $this->artisan('quiz:post', ['--force' => true])->assertExitCode(1);

    expect($this->fake->sentPolls)->toBeEmpty();
});

it('throttles the inline fallback generation across per-minute runs', function () {
    QuizTopic::factory()->create();

    QuizAuthoringAgent::fake(['not a tool call at all']);

    $this->artisan('quiz:post')->assertExitCode(1);

    // A second run one minute later must not ask the authoring model again.
    $this->travelTo(now()->addMinute());
    $this->artisan('quiz:post')->assertExitCode(0);

    expect(DailyQuiz::query()->count())->toBe(0);
});
