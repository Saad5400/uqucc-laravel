<?php

use App\Jobs\GenerateOpinionPollJob;
use App\Models\OpinionPoll;
use App\Models\User;
use App\Services\OpinionPoll\OpinionPollPoster;
use App\Settings\OpinionPollSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeTelegramApi;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->fake = new FakeTelegramApi;
    $this->app->bind(
        OpinionPollPoster::class,
        fn (): OpinionPollPoster => new OpinionPollPoster(app(OpinionPollSettings::class), $this->fake),
    );
});

/**
 * A complete, valid poll payload — the shape the editor dialog submits.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function opinionPollPayload(array $overrides = []): array
{
    return [
        'poll_date' => today()->toDateString(),
        'question' => 'ما المحرر الذي تكتب به أكثر؟',
        'options' => ['VS Code', 'Vim'],
        'post_time' => null,
        ...$overrides,
    ];
}

/** Turn the feature on so the posting actions are available. */
function configureOpinionPolls(): void
{
    $settings = app(OpinionPollSettings::class);
    $settings->enabled = true;
    $settings->chat_ids = ['-100200300'];
    $settings->save();
}

it('redirects guests to the login page', function () {
    $this->get('/manage/polls')->assertRedirect('/manage/login');
});

it('renders the page with the queue, the live poll and past results', function () {
    OpinionPoll::factory()->create(['poll_date' => today()->addDay()]);
    OpinionPoll::factory()->posted()->create(['poll_date' => today()]);
    OpinionPoll::factory()->closed()->create(['poll_date' => today()->subDay()]);

    $this->actingAs($this->admin)
        ->get('/manage/polls')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('manage/polls/Index')
            ->has('settings', fn (Assert $settings) => $settings
                ->has('enabled')->has('chat_ids')->has('post_time')->has('open_hours'))
            ->where('livePoll.poll_date', today()->toDateString())
            ->where('currentPoll.poll_date', today()->addDay()->toDateString())
            ->has('upcoming', 2)
            ->has('recent', 1)
            ->where('recent.0.total_votes', 13)
            ->has('suggestions')
            ->has('themes', 8)
            ->has('aiDisabledReason')
            ->where('limits.question', 300)
            ->where('limits.max_options', 10)
            ->where('today', today()->toDateString())
            ->where('nextFreeDate', today()->addDays(2)->toDateString()));
});

describe('settings', function () {
    it('saves the switch, the groups, the hour and the window', function () {
        $this->actingAs($this->admin)
            ->put('/manage/polls/settings', [
                'enabled' => true,
                'chat_ids' => ['-100200300', '-100400500:42'],
                'post_time' => '21:30',
                'open_hours' => 12,
            ])
            ->assertRedirect();

        $settings = app(OpinionPollSettings::class)->refresh();

        expect($settings->enabled)->toBeTrue()
            ->and($settings->chat_ids)->toBe(['-100200300', '-100400500:42'])
            ->and($settings->post_time)->toBe('21:30')
            ->and($settings->open_hours)->toBe(12);
    });

    it('rejects a bad chat id, hour or window', function (array $payload, string $field) {
        $this->actingAs($this->admin)
            ->put('/manage/polls/settings', [
                'enabled' => true,
                'chat_ids' => [],
                'post_time' => '20:00',
                'open_hours' => 24,
                ...$payload,
            ])
            ->assertSessionHasErrors($field);
    })->with([
        'chat id' => [['chat_ids' => ['not-a-chat']], 'chat_ids.0'],
        'duplicate chat id' => [['chat_ids' => ['-100200300', '-100200300']], 'chat_ids.0'],
        'hour' => [['post_time' => '9 مساءً'], 'post_time'],
        'window too long' => [['open_hours' => 999], 'open_hours'],
        'window too short' => [['open_hours' => 0], 'open_hours'],
    ]);
});

describe('writing polls', function () {
    it('queues a poll', function () {
        $this->actingAs($this->admin)
            ->post('/manage/polls', opinionPollPayload())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $poll = OpinionPoll::forDate(today());

        expect($poll)->not->toBeNull()
            ->and($poll->question)->toBe('ما المحرر الذي تكتب به أكثر؟')
            ->and($poll->options)->toBe(['VS Code', 'Vim'])
            ->and($poll->isReady())->toBeTrue();
    });

    it('drops the blank option rows the editor leaves behind', function () {
        $this->actingAs($this->admin)
            ->post('/manage/polls', opinionPollPayload(['options' => ['نعم', 'لا', '', '  ']]))
            ->assertSessionHasNoErrors();

        expect(OpinionPoll::forDate(today())->options)->toBe(['نعم', 'لا']);
    });

    it('rejects an invalid poll', function (array $payload, string $field) {
        $this->actingAs($this->admin)
            ->post('/manage/polls', opinionPollPayload($payload))
            ->assertSessionHasErrors($field);
    })->with([
        'no question' => [['question' => ''], 'question'],
        'question too long' => [['question' => str_repeat('ا', 301)], 'question'],
        'one option' => [['options' => ['نعم']], 'options'],
        'eleven options' => [['options' => range(1, 11)], 'options'],
        'repeated options' => [['options' => ['نعم', 'نعم']], 'options.0'],
        'option too long' => [['options' => ['نعم', str_repeat('ا', 101)]], 'options.1'],
        'bad time' => [['post_time' => '20'], 'post_time'],
    ]);

    it('keeps one poll per day', function () {
        OpinionPoll::factory()->create(['poll_date' => today()]);

        $this->actingAs($this->admin)
            ->post('/manage/polls', opinionPollPayload())
            ->assertSessionHasErrors('poll_date');

        expect(OpinionPoll::query()->count())->toBe(1);
    });

    it('edits and deletes a queued poll', function () {
        $poll = OpinionPoll::factory()->create();

        $this->actingAs($this->admin)
            ->put("/manage/polls/{$poll->id}", opinionPollPayload(['question' => 'سؤال آخر؟']))
            ->assertSessionHasNoErrors();

        expect($poll->refresh()->question)->toBe('سؤال آخر؟');

        $this->actingAs($this->admin)
            ->delete("/manage/polls/{$poll->id}")
            ->assertSessionHasNoErrors();

        expect(OpinionPoll::query()->count())->toBe(0);
    });

    it('will not edit or delete a poll the group is already voting in', function () {
        $poll = OpinionPoll::factory()->posted()->create();

        $this->actingAs($this->admin)
            ->put("/manage/polls/{$poll->id}", opinionPollPayload(['question' => 'سؤال آخر؟']))
            ->assertSessionHasErrors('poll');

        $this->actingAs($this->admin)
            ->delete("/manage/polls/{$poll->id}")
            ->assertSessionHasErrors('poll');

        expect($poll->refresh()->question)->not->toBe('سؤال آخر؟')
            ->and(OpinionPoll::query()->count())->toBe(1);
    });
});

describe('generating from the panel', function () {
    it('queues a generation for the chosen day and angle', function () {
        Queue::fake();

        $this->actingAs($this->admin)
            ->post('/manage/polls/generate', ['date' => today()->addDay()->toDateString(), 'theme' => 'tools'])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(
            GenerateOpinionPollJob::class,
            fn (GenerateOpinionPollJob $job): bool => $job->date === today()->addDay()->toDateString()
                && $job->theme === 'tools'
                && $job->replace === false,
        );
    });

    it('regenerates a day that already holds a queued poll', function () {
        Queue::fake();
        OpinionPoll::factory()->create();

        $this->actingAs($this->admin)
            ->post('/manage/polls/generate', [])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(
            GenerateOpinionPollJob::class,
            fn (GenerateOpinionPollJob $job): bool => $job->theme === null && $job->replace === true,
        );
    });

    it('refuses a day whose poll already went out', function () {
        Queue::fake();
        OpinionPoll::factory()->posted()->create();

        $this->actingAs($this->admin)
            ->post('/manage/polls/generate', [])
            ->assertSessionHasErrors('generate');

        Queue::assertNothingPushed();
    });

    it('rejects a past day or an unknown angle', function (array $payload, string $field) {
        Queue::fake();

        $this->actingAs($this->admin)
            ->post('/manage/polls/generate', $payload)
            ->assertSessionHasErrors($field);

        Queue::assertNothingPushed();
    })->with([
        'past day' => [['date' => '2020-01-01'], 'date'],
        'unknown angle' => [['theme' => 'not-a-theme'], 'theme'],
    ]);
});

describe('posting and closing by hand', function () {
    it('posts a queued poll to the groups now', function () {
        configureOpinionPolls();
        $poll = OpinionPoll::factory()->create();

        $this->actingAs($this->admin)
            ->post("/manage/polls/{$poll->id}/post")
            ->assertSessionHasNoErrors();

        expect($this->fake->sentPolls)->toHaveCount(1)
            ->and($poll->refresh()->isPosted())->toBeTrue();
    });

    it('refuses to post while the feature is unconfigured', function () {
        $poll = OpinionPoll::factory()->create();

        $this->actingAs($this->admin)
            ->post("/manage/polls/{$poll->id}/post")
            ->assertSessionHasErrors('post');

        expect($this->fake->sentPolls)->toBeEmpty();
    });

    it('refuses to post a poll that already closed', function () {
        configureOpinionPolls();
        $poll = OpinionPoll::factory()->closed()->create();

        $this->actingAs($this->admin)
            ->post("/manage/polls/{$poll->id}/post")
            ->assertSessionHasErrors('post');
    });

    it('closes a live poll and announces its result', function () {
        configureOpinionPolls();
        $poll = OpinionPoll::factory()->posted()->create(['options' => ['نعم', 'لا']]);
        $this->fake->pollResults[$poll->posts()->first()->message_id] = [4, 1];

        $this->actingAs($this->admin)
            ->post("/manage/polls/{$poll->id}/close")
            ->assertSessionHasNoErrors();

        $poll->refresh();

        expect($poll->isClosed())->toBeTrue()
            ->and($poll->results)->toBe([4, 1])
            ->and($this->fake->sentMessages)->toHaveCount(1);
    });

    it('refuses to close a poll that is not live', function () {
        $poll = OpinionPoll::factory()->create();

        $this->actingAs($this->admin)
            ->post("/manage/polls/{$poll->id}/close")
            ->assertSessionHasErrors('post');
    });
});
