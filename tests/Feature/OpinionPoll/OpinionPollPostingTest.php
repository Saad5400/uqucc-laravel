<?php

use App\Models\OpinionPoll;
use App\Models\OpinionPollPost;
use App\Services\OpinionPoll\OpinionPollPoster;
use App\Services\OpinionPoll\OpinionPollSchedule;
use App\Settings\OpinionPollSettings;
use Tests\Fakes\FakeTelegramApi;

beforeEach(function () {
    // The command runs every minute and posts only once its moment arrives;
    // sit on the default posting time so the clock is never the reason a test
    // sees nothing.
    $this->travelTo(today()->setTimeFromTimeString(OpinionPollSchedule::DEFAULT_POST_TIME));

    $settings = app(OpinionPollSettings::class);
    $settings->enabled = true;
    $settings->chat_ids = ['-100200300'];
    $settings->save();

    $this->fake = new FakeTelegramApi;
    $this->app->bind(
        OpinionPollPoster::class,
        fn (): OpinionPollPoster => new OpinionPollPoster(app(OpinionPollSettings::class), $this->fake),
    );
});

/** Post today's queued poll through the scheduled command. */
function runPollCommand(array $options = []): void
{
    test()->artisan('poll:post', $options)->assertExitCode(0);
}

describe('posting the day\'s poll', function () {
    it('sends today\'s poll to every configured group as an anonymous poll', function () {
        app(OpinionPollSettings::class)->fill(['chat_ids' => ['-100200300', '-100400500:42']])->save();

        OpinionPoll::factory()->create([
            'question' => 'ما المحرر الذي تكتب به أكثر؟',
            'options' => ['VS Code', 'Vim'],
        ]);

        runPollCommand();

        expect($this->fake->sentPolls)->toHaveCount(2)
            ->and($this->fake->sentPolls[0]['question'])->toBe('ما المحرر الذي تكتب به أكثر؟')
            ->and($this->fake->sentPolls[0]['options'])->toBe(['VS Code', 'Vim'])
            ->and($this->fake->sentPolls[0]['is_anonymous'])->toBeTrue()
            ->and($this->fake->sentPolls[0]['chat_id'])->toBe(-100200300)
            ->and($this->fake->sentPolls[0])->not->toHaveKey('type')
            ->and($this->fake->sentPolls[1]['chat_id'])->toBe(-100400500)
            ->and($this->fake->sentPolls[1]['message_thread_id'])->toBe(42);
    });

    it('records one post per group and marks the poll live with its closing moment', function () {
        app(OpinionPollSettings::class)->fill(['open_hours' => 12])->save();

        $poll = OpinionPoll::factory()->create();

        runPollCommand();

        $poll->refresh();

        expect($poll->status)->toBe(OpinionPoll::STATUS_POSTED)
            ->and($poll->posted_at)->not->toBeNull()
            ->and($poll->closes_at->toDateTimeString())->toBe(now()->addHours(12)->toDateTimeString())
            ->and($poll->posts()->count())->toBe(1);

        $post = $poll->posts()->first();

        expect($post->chat_id)->toBe(-100200300)
            ->and($post->telegram_poll_id)->not->toBeNull()
            ->and($post->closed_at)->toBeNull();
    });

    it('waits for the poll\'s moment', function () {
        $this->travelTo(today()->setTimeFromTimeString('19:59'));

        OpinionPoll::factory()->create();

        runPollCommand();

        expect($this->fake->sentPolls)->toBeEmpty()
            ->and(OpinionPoll::forDate(today())->isReady())->toBeTrue();
    });

    it('honours a poll\'s own posting time', function () {
        $this->travelTo(today()->setTimeFromTimeString('09:05'));

        OpinionPoll::factory()->create(['post_time' => '09:00']);

        runPollCommand();

        expect($this->fake->sentPolls)->toHaveCount(1);
    });

    // AI is left unconfigured throughout this file, so posting never reaches
    // its inline fallback here and an empty day stays empty. The fallback has
    // its own coverage in OpinionPollGenerationTest.
    it('posts nothing on a day with an empty queue it cannot author for', function () {
        OpinionPoll::factory()->create(['poll_date' => today()->addDay()]);

        runPollCommand();

        expect($this->fake->sentPolls)->toBeEmpty();
    });

    it('posts nothing while the feature is off or has no groups', function (array $settings) {
        app(OpinionPollSettings::class)->fill($settings)->save();

        OpinionPoll::factory()->create();

        runPollCommand();

        expect($this->fake->sentPolls)->toBeEmpty()
            ->and(OpinionPoll::forDate(today())->isReady())->toBeTrue();
    })->with([
        'disabled' => [['enabled' => false]],
        'no groups' => [['chat_ids' => []]],
    ]);

    it('posts ahead of the moment when forced', function () {
        $this->travelTo(today()->setTimeFromTimeString('12:00'));

        OpinionPoll::factory()->create();

        runPollCommand(['--force' => true]);

        expect($this->fake->sentPolls)->toHaveCount(1)
            ->and(OpinionPoll::forDate(today())->isPosted())->toBeTrue();
    });

    it('posts a live poll only once', function () {
        OpinionPoll::factory()->create();

        runPollCommand();
        runPollCommand();

        expect($this->fake->sentPolls)->toHaveCount(1);
    });

    it('re-posts a live poll on demand without announcing a result', function () {
        $poll = OpinionPoll::factory()->create();

        runPollCommand();

        $firstMessageId = $poll->posts()->first()->message_id;
        $this->fake->pollResults[$firstMessageId] = [4, 1];

        runPollCommand(['--force' => true]);

        expect($this->fake->sentPolls)->toHaveCount(2)
            ->and($this->fake->sentMessages)->toBeEmpty()
            ->and($poll->refresh()->status)->toBe(OpinionPoll::STATUS_POSTED)
            ->and($poll->posts()->count())->toBe(1)
            ->and($poll->posts()->first()->message_id)->not->toBe($firstMessageId);
    });
});

describe('closing the poll and announcing the result', function () {
    it('closes a poll whose window has run out and reports what the group chose', function () {
        $poll = OpinionPoll::factory()->create([
            'question' => 'ما المحرر الذي تكتب به أكثر؟',
            'options' => ['VS Code', 'Vim', 'شيء آخر'],
        ]);

        runPollCommand();

        $this->fake->pollResults[$poll->posts()->first()->message_id] = [6, 3, 1];

        $this->travel(25)->hours();
        runPollCommand();

        $poll->refresh();

        expect($poll->status)->toBe(OpinionPoll::STATUS_CLOSED)
            ->and($poll->results)->toBe([6, 3, 1])
            ->and($poll->totalVotes())->toBe(10)
            ->and($poll->posts()->first()->closed_at)->not->toBeNull()
            ->and($this->fake->stoppedPolls)->toHaveCount(1)
            ->and($this->fake->sentMessages)->toHaveCount(1);

        $recap = $this->fake->sentMessages[0];

        expect($recap['chat_id'])->toBe(-100200300)
            ->and($recap['reply_to_message_id'])->toBe($poll->posts()->first()->message_id)
            ->and($recap['text'])->toContain('ما المحرر الذي تكتب به أكثر؟')
            ->and($recap['text'])->toContain('🥇 VS Code — 60٪ (6)')
            ->and($recap['text'])->toContain('🥈 Vim — 30٪ (3)')
            ->and($recap['text'])->toContain('🥉 شيء آخر — 10٪ (1)')
            ->and($recap['text'])->toContain('10 مشاركين');
    });

    it('keeps the poll open until its window is up', function () {
        $poll = OpinionPoll::factory()->create();

        runPollCommand();

        $this->travel(23)->hours();
        runPollCommand();

        expect($poll->refresh()->isPosted())->toBeTrue()
            ->and($this->fake->stoppedPolls)->toBeEmpty();
    });

    it('sums the votes of every group into one result', function () {
        app(OpinionPollSettings::class)->fill(['chat_ids' => ['-100200300', '-100400500']])->save();

        $poll = OpinionPoll::factory()->create(['options' => ['نعم', 'لا']]);

        runPollCommand();

        foreach ($poll->posts as $index => $post) {
            $this->fake->pollResults[$post->message_id] = $index === 0 ? [5, 2] : [1, 4];
        }

        $this->travel(25)->hours();
        runPollCommand();

        expect($poll->refresh()->results)->toBe([6, 6])
            ->and($this->fake->sentMessages)->toHaveCount(2);
    });

    it('says nothing when nobody voted', function () {
        OpinionPoll::factory()->create();

        runPollCommand();

        $this->travel(25)->hours();
        runPollCommand();

        expect(OpinionPoll::forDate(today()->subDay())->isClosed())->toBeTrue()
            ->and($this->fake->sentMessages)->toBeEmpty();
    });

    it('still closes a live poll after the feature is switched off', function () {
        $poll = OpinionPoll::factory()->create();

        runPollCommand();

        $this->fake->pollResults[$poll->posts()->first()->message_id] = [2, 1, 0, 0];

        app(OpinionPollSettings::class)->fill(['enabled' => false])->save();

        $this->travel(25)->hours();
        runPollCommand();

        expect($poll->refresh()->isClosed())->toBeTrue()
            ->and($this->fake->sentMessages)->toHaveCount(1);
    });

    it('closes the poll still open when the next one goes out early', function () {
        $yesterday = OpinionPoll::factory()->create([
            'poll_date' => today()->subDay(),
            'options' => ['نعم', 'لا'],
        ]);
        $yesterday->update([
            'status' => OpinionPoll::STATUS_POSTED,
            'posted_at' => now()->subHours(2),
            'closes_at' => now()->addHours(22),
        ]);
        OpinionPollPost::factory()->create(['opinion_poll_id' => $yesterday->id, 'message_id' => 777]);

        $this->fake->pollResults[777] = [3, 1];

        OpinionPoll::factory()->create(['options' => ['نعم', 'لا']]);

        runPollCommand();

        expect($yesterday->refresh()->isClosed())->toBeTrue()
            ->and($yesterday->results)->toBe([3, 1])
            ->and($this->fake->stoppedPolls)->toHaveCount(1)
            ->and($this->fake->sentMessages)->toHaveCount(1)
            ->and(OpinionPoll::forDate(today())->isPosted())->toBeTrue();
    });

    it('marks the poll closed even when its message is gone from the group', function () {
        $poll = OpinionPoll::factory()->create();

        runPollCommand();

        $this->fake->missingMessageIds = [$poll->posts()->first()->message_id];

        $this->travel(25)->hours();
        runPollCommand();

        expect($poll->refresh()->isClosed())->toBeTrue();
    });
});
