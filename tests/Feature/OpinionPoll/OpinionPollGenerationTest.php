<?php

use App\Ai\OpinionPoll\OpinionPollAuthor;
use App\Ai\OpinionPoll\OpinionPollAuthoringAgent;
use App\Ai\OpinionPoll\OpinionPollTheme;
use App\Models\OpinionPoll;
use App\Services\OpinionPoll\OpinionPollPoster;
use App\Services\OpinionPoll\OpinionPollSchedule;
use App\Settings\AiSettings;
use App\Settings\OpinionPollSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ToolCall;
use Saad\AiKit\Safety\BudgetGuard;
use Saad\AiKit\Safety\Exceptions\BudgetExceededException;
use Tests\Fakes\FakeTelegramApi;

beforeEach(function () {
    Cache::flush();
    config()->set('ai.providers.openrouter.key', 'test-key');

    $ai = app(AiSettings::class);
    $ai->ai_enabled = true;
    $ai->daily_budget_usd = 5.0;
    $ai->save();

    $polls = app(OpinionPollSettings::class);
    $polls->enabled = true;
    $polls->chat_ids = ['-100200300'];
    $polls->save();
});

/**
 * A faked model turn that submits a poll through the submit_opinion_poll tool.
 * The fake gateway runs the REAL tool, so overriding fields here exercises the
 * tool's actual validation.
 */
function pollToolCall(array $overrides = []): ToolCall
{
    return new ToolCall('call_1', 'submit_opinion_poll', [
        'question' => 'ما المحرر الذي تكتب به أكثر؟',
        'options' => ['VS Code', 'Vim', 'شيء آخر'],
        ...$overrides,
    ]);
}

describe('authoring', function () {
    it('generates a ready poll for today', function () {
        OpinionPollAuthoringAgent::fake([pollToolCall()]);

        $this->artisan('poll:generate')->assertExitCode(0);

        $poll = OpinionPoll::forDate(today());

        expect($poll)->not->toBeNull()
            ->and($poll->status)->toBe(OpinionPoll::STATUS_READY)
            ->and($poll->question)->toBe('ما المحرر الذي تكتب به أكثر؟')
            ->and($poll->options)->toBe(['VS Code', 'Vim', 'شيء آخر'])
            ->and($poll->theme)->toBeInstanceOf(OpinionPollTheme::class);
    });

    it('generates for an explicit day', function () {
        OpinionPollAuthoringAgent::fake([pollToolCall()]);

        $this->artisan('poll:generate', ['--date' => today()->addDays(3)->toDateString()])->assertExitCode(0);

        expect(OpinionPoll::forDate(today()))->toBeNull()
            ->and(OpinionPoll::forDate(today()->addDays(3)))->not->toBeNull();
    });

    it('leaves a day that already has a poll alone', function () {
        OpinionPoll::factory()->create(['question' => 'سؤال مكتوب باليد']);

        OpinionPollAuthoringAgent::fake([pollToolCall()]);

        $this->artisan('poll:generate')->assertExitCode(0);

        expect(OpinionPoll::query()->count())->toBe(1)
            ->and(OpinionPoll::forDate(today())->question)->toBe('سؤال مكتوب باليد');
    });

    it('skips silently while the feature is disabled', function () {
        app(OpinionPollSettings::class)->fill(['enabled' => false])->save();

        OpinionPollAuthoringAgent::fake([pollToolCall()]);

        $this->artisan('poll:generate')->assertExitCode(0);

        expect(OpinionPoll::query()->count())->toBe(0);
    });

    it('refuses to run with the AI switch off or no provider key', function (callable $break) {
        $break();

        expect(app(OpinionPollAuthor::class)->disabledReason())->not->toBeNull();

        $this->artisan('poll:generate')->assertExitCode(1);

        expect(OpinionPoll::query()->count())->toBe(0);
    })->with([
        'ai off' => [fn () => app(AiSettings::class)->fill(['ai_enabled' => false])->save()],
        'no key' => [fn () => config()->set('ai.providers.openrouter.key', '')],
    ]);

    it('refuses to run once the daily budget is spent', function () {
        $this->partialMock(BudgetGuard::class, fn ($mock) => $mock->shouldReceive('exceeded')->andReturnTrue());

        expect(app(OpinionPollAuthor::class)->disabledReason())->not->toBeNull();

        $this->artisan('poll:generate')->assertExitCode(1);

        expect(OpinionPoll::query()->count())->toBe(0);
        OpinionPollAuthoringAgent::assertNeverPrompted();
    });

    it('does not retry once the budget is spent mid-run', function () {
        $turns = 0;
        OpinionPollAuthoringAgent::fake(function () use (&$turns) {
            $turns++;

            throw new BudgetExceededException(9.0, 5.0, 3600);
        });

        $this->artisan('poll:generate')->assertExitCode(1);

        expect($turns)->toBe(1)
            ->and(OpinionPoll::query()->count())->toBe(0);
    });

    it('rotates through the themes, the one waiting longest first', function () {
        // Nothing asked yet: the rotation starts at the head of the vocabulary.
        $first = OpinionPollTheme::all()[0];

        expect(OpinionPoll::nextTheme())->toBe($first);

        OpinionPoll::factory()->create(['poll_date' => today()->subDay(), 'theme' => $first]);

        // A theme nobody has asked from beats one asked yesterday.
        expect(OpinionPoll::nextTheme())->toBe(OpinionPollTheme::all()[1]);

        // With every theme spent, the one asked longest ago comes back around.
        OpinionPoll::query()->delete();

        foreach (OpinionPollTheme::all() as $index => $theme) {
            OpinionPoll::factory()->create([
                'poll_date' => today()->subDays(30 - $index),
                'theme' => $theme,
            ]);
        }

        expect(OpinionPoll::nextTheme())->toBe($first);
    });

    it('honours a forced theme and records it', function () {
        OpinionPollAuthoringAgent::fake([pollToolCall()]);

        app(OpinionPollAuthor::class)->generateForDate(today(), OpinionPollTheme::LightDebate);

        expect(OpinionPoll::forDate(today())->theme)->toBe(OpinionPollTheme::LightDebate);
    });

    it('regenerates a ready poll only when asked to replace it', function () {
        OpinionPoll::factory()->create(['question' => 'القديم']);

        OpinionPollAuthoringAgent::fake([pollToolCall()]);

        expect(fn () => app(OpinionPollAuthor::class)->generateForDate(today()))
            ->toThrow(RuntimeException::class);

        OpinionPollAuthoringAgent::fake([pollToolCall()]);

        app(OpinionPollAuthor::class)->generateForDate(today(), replace: true);

        expect(OpinionPoll::query()->count())->toBe(1)
            ->and(OpinionPoll::forDate(today())->question)->toBe('ما المحرر الذي تكتب به أكثر؟');
    });

    it('never touches a poll that already went out', function () {
        OpinionPoll::factory()->posted()->create(['question' => 'المنشور']);

        OpinionPollAuthoringAgent::fake([pollToolCall()]);

        expect(fn () => app(OpinionPollAuthor::class)->generateForDate(today(), replace: true))
            ->toThrow(RuntimeException::class);

        expect(OpinionPoll::forDate(today())->question)->toBe('المنشور');
    });

    it('lists the recent polls in the prompt so the model does not repeat them', function () {
        OpinionPoll::factory()->create(['poll_date' => today()->subDay(), 'question' => 'كم تنام في أيام الاختبارات؟']);

        OpinionPollAuthoringAgent::fake([pollToolCall()]);

        app(OpinionPollAuthor::class)->generateForDate(today());

        OpinionPollAuthoringAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => str_contains((string) $prompt->prompt, 'كم تنام في أيام الاختبارات؟'),
        );
    });

    it('retries the whole run when the first attempt submits nothing', function () {
        OpinionPollAuthoringAgent::fake(['نص بلا استدعاء أداة', pollToolCall()]);

        $this->artisan('poll:generate')->assertExitCode(0);

        expect(OpinionPoll::forDate(today()))->not->toBeNull();
    });

    it('gives up for the day when every call times out upstream', function () {
        OpinionPollAuthoringAgent::fake(fn () => throw new ConnectionException('cURL error 28'));

        $this->artisan('poll:generate')->assertExitCode(1);

        expect(OpinionPoll::query()->count())->toBe(0);
    });

    it('gives up for the day after the attempts are spent', function () {
        OpinionPollAuthoringAgent::fake(fn () => throw new AiException('upstream error'));

        $this->artisan('poll:generate')->assertExitCode(1);

        expect(OpinionPoll::query()->count())->toBe(0);
    });
});

describe('the submit tool\'s quality gate', function () {
    it('rejects a poll and takes the model\'s correction', function (array $bad) {
        OpinionPollAuthoringAgent::fake([pollToolCall($bad), pollToolCall()]);

        $this->artisan('poll:generate')->assertExitCode(0);

        $poll = OpinionPoll::forDate(today());

        expect($poll->question)->toBe('ما المحرر الذي تكتب به أكثر؟')
            ->and($poll->options)->toBe(['VS Code', 'Vim', 'شيء آخر']);
    })->with([
        'two options' => [['options' => ['نعم', 'لا']]],
        'six options' => [['options' => ['أ', 'ب', 'ج', 'د', 'هـ', 'و']]],
        'repeated options' => [['options' => ['Vim', 'Vim', 'شيء آخر']]],
        'empty option' => [['options' => ['VS Code', '', 'شيء آخر']]],
        'numbered options' => [['options' => ['1. VS Code', '2. Vim', '3. شيء آخر']]],
        'markup in the question' => [['question' => '<b>ما محررك؟</b>']],
        'long option' => [['options' => ['VS Code', str_repeat('ا', 61), 'شيء آخر']]],
        'long question' => [['question' => str_repeat('ا', 301)]],
        'empty question' => [['question' => '   ']],
    ]);

    it('gives up when the model never submits a valid poll', function () {
        $rejected = pollToolCall(['options' => ['نعم', 'لا']]);

        OpinionPollAuthoringAgent::fake([$rejected, $rejected]);

        $this->artisan('poll:generate')->assertExitCode(1);

        expect(OpinionPoll::query()->count())->toBe(0);
    });
});

describe('posting falls back to authoring one on the spot', function () {
    beforeEach(function () {
        $this->travelTo(today()->setTimeFromTimeString(OpinionPollSchedule::DEFAULT_POST_TIME));

        $this->fake = new FakeTelegramApi;
        $this->app->bind(
            OpinionPollPoster::class,
            fn (): OpinionPollPoster => new OpinionPollPoster(app(OpinionPollSettings::class), $this->fake),
        );
    });

    it('writes and posts a poll when the day was left empty', function () {
        OpinionPollAuthoringAgent::fake([pollToolCall()]);

        $this->artisan('poll:post')->assertExitCode(0);

        expect($this->fake->sentPolls)->toHaveCount(1)
            ->and($this->fake->sentPolls[0]['question'])->toBe('ما المحرر الذي تكتب به أكثر؟')
            ->and(OpinionPoll::forDate(today())->isPosted())->toBeTrue();
    });

    it('waits before trying the fallback again after a failure', function () {
        $turns = 0;
        OpinionPollAuthoringAgent::fake(function () use (&$turns) {
            $turns++;

            throw new AiException('upstream error');
        });

        $this->artisan('poll:post')->assertExitCode(1);

        $spentOnFirstRun = $turns;

        // The next minute falls inside the throttle window, so the failing
        // model must not be asked again.
        $this->artisan('poll:post')->assertExitCode(0);

        expect($turns)->toBe($spentOnFirstRun)
            ->and(OpinionPoll::query()->count())->toBe(0)
            ->and($this->fake->sentPolls)->toBeEmpty();
    });

    it('stays quiet when generation is unavailable', function () {
        app(AiSettings::class)->fill(['ai_enabled' => false])->save();

        $this->artisan('poll:post')->assertExitCode(0);

        expect(OpinionPoll::query()->count())->toBe(0)
            ->and($this->fake->sentPolls)->toBeEmpty();
    });

    it('does not author anything before the posting moment', function () {
        $this->travelTo(today()->setTimeFromTimeString('12:00'));

        $this->artisan('poll:post')->assertExitCode(0);

        expect(OpinionPoll::query()->count())->toBe(0);
    });
});
