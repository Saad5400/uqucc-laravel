<?php

use App\Ai\Quiz\QuizAuthor;
use App\Ai\Quiz\QuizAuthoringAgent;
use App\Models\DailyQuiz;
use App\Models\QuizTopic;
use App\Models\QuizTopicPoll;
use App\Services\Quiz\QuizImageRenderer;
use App\Services\Quiz\QuizPoster;
use App\Services\Quiz\QuizSchedule;
use App\Services\Quiz\QuizTopicVote;
use App\Settings\AiSettings;
use App\Settings\QuizSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fakes\FakeTelegramApi;

beforeEach(function () {
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

    $this->app->instance(QuizImageRenderer::class, new class extends QuizImageRenderer
    {
        public function render(DailyQuiz $quiz): string
        {
            return 'fake-png-bytes';
        }
    });

    $this->fake = new FakeTelegramApi;
    $this->app->bind(QuizPoster::class, fn (): QuizPoster => new QuizPoster(app(QuizSettings::class), $this->fake));
    // The tally runs at generation time, so the author's vote service needs the
    // same fake the poster writes ballots with.
    $this->app->bind(QuizTopicVote::class, fn (): QuizTopicVote => new QuizTopicVote(app(QuizSettings::class), $this->fake));
});

function topicVoteQuestion(): ToolCall
{
    return new ToolCall('call_1', 'submit_quiz_question', [
        'question' => 'ما البوابة المنطقية التي تعكس قيمة المدخل؟',
        'options' => ['AND', 'OR', 'NOT', 'XOR'],
        'correct_option' => 2,
        'explanation' => 'بوابة NOT تُخرج عكس قيمة المدخل دائماً.',
    ]);
}

/** Every ballot the bot sent, as raw sendPoll parameters. */
function sentBallots(FakeTelegramApi $fake): array
{
    return array_values(array_filter(
        $fake->sentPolls,
        static fn (array $params): bool => ($params['question'] ?? null) === QuizTopicVote::QUESTION,
    ));
}

describe('opening the ballot', function () {
    it('puts four of the cycle\'s remaining topics to a vote under the question', function () {
        QuizTopic::factory()->count(6)->create();
        DailyQuiz::factory()->create(['quiz_date' => today()]);

        $this->artisan('quiz:post')->assertExitCode(0);

        $ballots = sentBallots($this->fake);

        expect($ballots)->toHaveCount(1)
            ->and($ballots[0]['chat_id'])->toBe(-100200300)
            ->and($ballots[0]['options'])->toHaveCount(4)
            ->and($ballots[0]['is_anonymous'])->toBeTrue()
            ->and($ballots[0])->not->toHaveKey('type');

        $poll = QuizTopicPoll::forDate(today()->addDay());

        expect($poll)->not->toBeNull()
            ->and($poll->topic_ids)->toHaveCount(4)
            ->and($poll->closed_at)->toBeNull()
            ->and($poll->quiz_topic_id)->toBeNull()
            ->and($poll->posts)->toHaveCount(1)
            ->and($ballots[0]['options'])->toBe($poll->ballot()->pluck('name')->all());
    });

    it('sends the ballot only after the question itself', function () {
        QuizTopic::factory()->count(6)->create();
        DailyQuiz::factory()->create(['quiz_date' => today()]);

        $this->artisan('quiz:post')->assertExitCode(0);

        expect($this->fake->sentPolls)->toHaveCount(2)
            ->and($this->fake->sentPolls[0]['question'])->toBe(QuizPoster::POLL_QUESTION)
            ->and($this->fake->sentPolls[1]['question'])->toBe(QuizTopicVote::QUESTION);
    });

    it('offers only topics the running cycle still owes', function () {
        QuizTopic::factory()->count(3)->usedThisCycle()->create();
        $pending = QuizTopic::factory()->count(4)->create();

        DailyQuiz::factory()->create(['quiz_date' => today()]);

        $this->artisan('quiz:post')->assertExitCode(0);

        expect(QuizTopicPoll::forDate(today()->addDay())->topic_ids)
            ->toEqualCanonicalizing($pending->modelKeys());
    });

    it('skips the vote when the cycle owes fewer topics than a ballot holds', function (int $pending) {
        QuizTopic::factory()->count(5)->usedThisCycle()->create();
        QuizTopic::factory()->count($pending)->create();

        DailyQuiz::factory()->create(['quiz_date' => today()]);

        $this->artisan('quiz:post')->assertExitCode(0);

        expect(sentBallots($this->fake))->toBeEmpty()
            ->and(QuizTopicPoll::query()->count())->toBe(0);
    })->with([
        'one left' => [1],
        'three left' => [3],
    ]);

    it('skips the vote when tomorrow\'s question is already written', function () {
        QuizTopic::factory()->count(6)->create();

        DailyQuiz::factory()->create(['quiz_date' => today()]);
        DailyQuiz::factory()->create(['quiz_date' => today()->addDay()]);

        $this->artisan('quiz:post')->assertExitCode(0);

        expect(sentBallots($this->fake))->toBeEmpty()
            ->and(QuizTopicPoll::query()->count())->toBe(0);
    });

    it('never opens a second ballot for the same day when the question is re-posted', function () {
        QuizTopic::factory()->count(6)->create();
        DailyQuiz::factory()->create(['quiz_date' => today()]);

        $this->artisan('quiz:post')->assertExitCode(0);
        $this->artisan('quiz:post', ['--force' => true])->assertExitCode(0);

        expect(sentBallots($this->fake))->toHaveCount(1)
            ->and(QuizTopicPoll::query()->count())->toBe(1);
    });

    it('offers spotlight topics when tomorrow is the spotlight day', function () {
        $this->travelTo(today()->next(CarbonInterface::TUESDAY)->setTimeFromTimeString(QuizSchedule::DEFAULT_POST_TIME));

        QuizTopic::factory()->count(5)->create();
        $spotlight = QuizTopic::factory()->count(4)->spotlight()->create();

        DailyQuiz::factory()->create(['quiz_date' => today()]);

        $this->artisan('quiz:post')->assertExitCode(0);

        expect(QuizTopicPoll::forDate(today()->addDay())->topic_ids)
            ->toEqualCanonicalizing($spotlight->modelKeys());
    });

    it('sends the ballot into the same forum topic as the question', function () {
        $settings = app(QuizSettings::class);
        $settings->chat_ids = ['-100200300:42'];
        $settings->save();

        QuizTopic::factory()->count(6)->create();
        DailyQuiz::factory()->create(['quiz_date' => today()]);

        $this->artisan('quiz:post')->assertExitCode(0);

        expect(sentBallots($this->fake)[0]['message_thread_id'])->toBe(42)
            ->and(QuizTopicPoll::forDate(today()->addDay())->posts[0]['message_thread_id'])->toBe(42);
    });

    it('still counts the question as posted when the ballot cannot be sent', function () {
        $flaky = new class extends FakeTelegramApi
        {
            public function sendPoll(array $params): \Telegram\Bot\Objects\Message
            {
                if (($params['question'] ?? null) === QuizTopicVote::QUESTION) {
                    throw new RuntimeException('Forbidden: polls are restricted in this chat');
                }

                return parent::sendPoll($params);
            }
        };

        $this->app->bind(QuizPoster::class, fn (): QuizPoster => new QuizPoster(app(QuizSettings::class), $flaky));

        QuizTopic::factory()->count(6)->create();
        $quiz = DailyQuiz::factory()->create(['quiz_date' => today()]);

        $this->artisan('quiz:post')->assertExitCode(0);

        expect($quiz->refresh()->status)->toBe(DailyQuiz::STATUS_POSTED)
            ->and(QuizTopicPoll::query()->count())->toBe(0);
    });
});

describe('tallying the ballot', function () {
    it('generates the question from the topic the group voted for', function () {
        $topics = QuizTopic::factory()->count(4)->create();
        $poll = QuizTopicPoll::factory()->forTopics($topics)->create(['quiz_date' => today()]);

        $this->fake->pollResults = [4242 => [1, 9, 2, 0]];

        QuizAuthoringAgent::fake([topicVoteQuestion()]);

        $this->artisan('quiz:generate')->assertExitCode(0);

        $poll->refresh();

        expect(DailyQuiz::forDate(today())->quiz_topic_id)->toBe($topics[1]->id)
            ->and($poll->quiz_topic_id)->toBe($topics[1]->id)
            ->and($poll->closed_at)->not->toBeNull()
            ->and($this->fake->stoppedPolls)->toHaveCount(1)
            ->and($topics[1]->refresh()->cycle_used_at)->not->toBeNull();
    });

    it('adds the votes up across every group the ballot reached', function () {
        $topics = QuizTopic::factory()->count(4)->create();
        QuizTopicPoll::factory()
            ->forTopics($topics)
            ->inChats(-100200300, -100400500)
            ->create(['quiz_date' => today()]);

        // Option 1 leads in the first group, but option 2 wins the room.
        $this->fake->pollResults = [4242 => [4, 1, 0, 0], 4243 => [0, 5, 0, 0]];

        QuizAuthoringAgent::fake([topicVoteQuestion()]);

        $this->artisan('quiz:generate')->assertExitCode(0);

        expect(DailyQuiz::forDate(today())->quiz_topic_id)->toBe($topics[1]->id)
            ->and($this->fake->stoppedPolls)->toHaveCount(2);
    });

    it('gives a tie to the topic that has waited longest', function () {
        $topics = QuizTopic::factory()->count(4)->create();
        QuizTopicPoll::factory()->forTopics($topics)->create(['quiz_date' => today()]);

        $this->fake->pollResults = [4242 => [5, 5, 0, 0]];

        QuizAuthoringAgent::fake([topicVoteQuestion()]);

        $this->artisan('quiz:generate')->assertExitCode(0);

        expect(DailyQuiz::forDate(today())->quiz_topic_id)->toBe($topics[0]->id);
    });

    it('falls back to the automatic pick when nobody voted', function () {
        $stale = QuizTopic::factory()->usedThisCycle()->create(['last_used_at' => now()->subDay()]);
        $topics = QuizTopic::factory()->count(4)->create();

        QuizTopicPoll::factory()->forTopics($topics)->create(['quiz_date' => today()]);

        QuizAuthoringAgent::fake([topicVoteQuestion()]);

        $this->artisan('quiz:generate')->assertExitCode(0);

        $poll = QuizTopicPoll::forDate(today());

        expect(DailyQuiz::forDate(today())->quiz_topic_id)->toBe($topics[0]->id)
            ->and(DailyQuiz::forDate(today())->quiz_topic_id)->not->toBe($stale->id)
            ->and($poll->closed_at)->not->toBeNull()
            ->and($poll->quiz_topic_id)->toBeNull();
    });

    it('falls back to the automatic pick when the winning topic was deactivated', function () {
        $topics = QuizTopic::factory()->count(4)->create();
        QuizTopicPoll::factory()->forTopics($topics)->create(['quiz_date' => today()]);

        $topics[1]->update(['is_active' => false]);
        $this->fake->pollResults = [4242 => [0, 9, 0, 0]];

        QuizAuthoringAgent::fake([topicVoteQuestion()]);

        $this->artisan('quiz:generate')->assertExitCode(0);

        expect(DailyQuiz::forDate(today())->quiz_topic_id)->toBe($topics[0]->id);
    });

    it('reuses a settled result instead of re-reading a closed ballot', function () {
        $topics = QuizTopic::factory()->count(4)->create();
        QuizTopicPoll::factory()->forTopics($topics)->create([
            'quiz_date' => today(),
            'quiz_topic_id' => $topics[2]->id,
            'closed_at' => now()->subHour(),
        ]);

        QuizAuthoringAgent::fake([topicVoteQuestion()]);

        $this->artisan('quiz:generate')->assertExitCode(0);

        expect(DailyQuiz::forDate(today())->quiz_topic_id)->toBe($topics[2]->id)
            ->and($this->fake->stoppedPolls)->toBeEmpty();
    });

    it('settles a still-open ballot when the day\'s question goes out unasked', function () {
        $topics = QuizTopic::factory()->count(4)->create();
        QuizTopicPoll::factory()->forTopics($topics)->create(['quiz_date' => today()]);

        // Written by hand in the panel, so generation never tallied anything.
        DailyQuiz::factory()->create(['quiz_date' => today()]);

        $this->artisan('quiz:post')->assertExitCode(0);

        expect(QuizTopicPoll::forDate(today())->closed_at)->not->toBeNull()
            ->and(collect($this->fake->stoppedPolls)->pluck('message_id')->all())->toContain(4242);
    });

    it('closes the ballot even when an admin names the topic themselves', function () {
        $topics = QuizTopic::factory()->count(4)->create();
        $chosen = QuizTopic::factory()->create(['name' => 'موضوع مختار']);

        QuizTopicPoll::factory()->forTopics($topics)->create(['quiz_date' => today()]);

        $this->fake->pollResults = [4242 => [0, 9, 0, 0]];

        QuizAuthoringAgent::fake([topicVoteQuestion()]);

        $quiz = app(QuizAuthor::class)->generateForDate(today(), $chosen);

        expect($quiz->quiz_topic_id)->toBe($chosen->id)
            ->and(QuizTopicPoll::forDate(today())->closed_at)->not->toBeNull();
    });
});

describe('the rotation cycle', function () {
    it('covers every topic before any topic repeats', function () {
        $topics = QuizTopic::factory()->count(5)->create();

        $picked = collect(range(1, 5))->map(function (): int {
            $topic = QuizTopic::pickForDate(today());
            $topic->markUsed();

            return $topic->id;
        });

        expect($picked->unique())->toHaveCount(5)
            ->and($picked->sort()->values()->all())->toBe($topics->modelKeys());
    });

    it('starts a fresh cycle once the pool is spent', function () {
        QuizTopic::factory()->count(3)->usedThisCycle()->create();

        expect(QuizTopic::pickForDate(today()))->not->toBeNull()
            ->and(QuizTopic::query()->whereNotNull('cycle_used_at')->count())->toBe(0);
    });

    it('keeps the two pools on their own cycles', function () {
        QuizTopic::factory()->count(2)->usedThisCycle()->create();
        $spotlight = QuizTopic::factory()->count(2)->spotlight()->create();

        $wednesday = today()->next(CarbonInterface::WEDNESDAY);

        expect(QuizTopic::cycleCandidates($wednesday)->modelKeys())->toEqualCanonicalizing($spotlight->modelKeys())
            // The spent regular pool is untouched until a regular day asks for it.
            ->and(QuizTopic::query()->whereNotNull('cycle_used_at')->count())->toBe(2);
    });
});
