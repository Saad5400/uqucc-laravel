<?php

use App\Helpers\Bidi;
use App\Models\QuizAnswer;
use App\Models\QuizPlayer;
use App\Models\TelegramTeam;
use App\Models\TelegramTeamMember;
use App\Services\Quiz\QuizLeaderboard;
use App\Services\Telegram\Handlers\QuizLeaderboardHandler;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Objects\Message;
use Tests\Fakes\FakeTelegramApi;

beforeEach(fn () => Cache::flush());

function leaderboardMessage(string $text, int $userId = 111, int $chatId = -100200300): Message
{
    return new Message([
        'message_id' => 10,
        'text' => $text,
        'chat' => ['id' => $chatId, 'type' => 'supergroup'],
        'from' => ['id' => $userId, 'is_bot' => false, 'first_name' => 'سعد'],
    ]);
}

it('shows the weekly and rolling leaderboards with the asking player\'s standing', function (string $trigger) {
    $ahmed = QuizPlayer::factory()->create([
        'first_name' => 'أحمد',
        'total_points' => 200,
        'current_streak' => 4,
        'answers_count' => 20,
    ]);
    $saad = QuizPlayer::factory()->create([
        'telegram_user_id' => 111,
        'first_name' => 'سعد',
        'total_points' => 90,
        'current_streak' => 2,
        'best_streak' => 6,
        'answers_count' => 9,
    ]);

    QuizAnswer::factory()->for($ahmed, 'player')->onQuizDate(today()->subDay())->create(['points' => 40]);
    QuizAnswer::factory()->for($saad, 'player')->onQuizDate(today())->create(['points' => 25]);

    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage($trigger));

    expect($api->sentMessages)->toHaveCount(1);

    $text = withoutBidi($api->sentMessages[0]['text']);

    expect($text)->toContain('هذا الأسبوع')
        ->toContain('آخر 30 يوماً')
        ->not->toContain('كل الأوقات')
        ->and($text)->toContain('أحمد')
        ->toContain('🥇')
        ->toContain('نتيجتك')
        ->toContain('هذا الأسبوع: 25 نقطة (ترتيبك 2)')
        ->toContain('آخر 30 يوماً: 25 نقطة (ترتيبك 2)');
})->with(['المتصدرين', '/leaderboard', '/leaderboard@UquccTestBot']);

it('ranks the rolling board on the window only, so an old lead ages out', function () {
    $veteran = QuizPlayer::factory()->create([
        'first_name' => 'قديم',
        'total_points' => 5000,
        'answers_count' => 300,
    ]);
    $newcomer = QuizPlayer::factory()->create([
        'first_name' => 'جديد',
        'total_points' => 60,
        'answers_count' => 5,
    ]);

    QuizAnswer::factory()
        ->for($veteran, 'player')
        ->onQuizDate(today()->subDays(QuizLeaderboard::WINDOW_DAYS))
        ->create(['points' => 5000]);
    QuizAnswer::factory()->for($newcomer, 'player')->onQuizDate(today())->create(['points' => 60]);

    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('المتصدرين', userId: 999));

    $text = withoutBidi($api->sentMessages[0]['text']);

    expect($text)->toContain('🥇 جديد')
        ->not->toContain('قديم');
});

it('shows a teaching empty state when nobody has played yet', function () {
    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('المتصدرين'));

    expect($api->sentMessages)->toHaveCount(1)
        ->and($api->sentMessages[0]['text'])->toContain('شارك في سؤال اليوم');
});

it('omits the personal section for someone who never played', function () {
    QuizPlayer::factory()->create(['total_points' => 200, 'answers_count' => 20]);

    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('المتصدرين', userId: 999));

    expect(withoutBidi($api->sentMessages[0]['text']))->not->toContain('نتيجتك');
});

it('rate-limits repeated leaderboard requests in the same chat', function () {
    QuizPlayer::factory()->create(['total_points' => 200, 'answers_count' => 20]);

    $api = new FakeTelegramApi;
    $handler = new QuizLeaderboardHandler($api);

    $handler->handle(leaderboardMessage('المتصدرين'));
    $handler->handle(leaderboardMessage('المتصدرين'));

    expect($api->sentMessages)->toHaveCount(1);

    // A different chat is on its own cooldown.
    $handler->handle(leaderboardMessage('المتصدرين', chatId: -100999888));

    expect($api->sentMessages)->toHaveCount(2);
});

it('ignores unrelated messages', function () {
    QuizPlayer::factory()->create(['answers_count' => 5]);

    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('كلام عادي عن المتصدرين في الدوري'));

    expect($api->sentMessages)->toBeEmpty();
});

/**
 * A player who answered today's question for `points`, in `team` if given —
 * the shape every team-board case is built from.
 */
function teamPlayer(int $telegramUserId, string $name, int $points, ?TelegramTeam $team = null): QuizPlayer
{
    $player = QuizPlayer::factory()->create([
        'telegram_user_id' => $telegramUserId,
        'first_name' => $name,
        'answers_count' => 1,
    ]);

    QuizAnswer::factory()->for($player, 'player')->onQuizDate(today())->create(['points' => $points]);

    if ($team !== null) {
        TelegramTeamMember::factory()->for($team, 'team')->create([
            'telegram_user_id' => $telegramUserId,
            'first_name' => $name,
        ]);
    }

    return $player;
}

/** The «المتصدرين» reply, as posted to the group. */
function leaderboardText(int $userId = 999): string
{
    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('المتصدرين', userId: $userId));

    return $api->sentMessages[0]['text'];
}

describe('the team board', function () {
    it('ranks the chat\'s teams by what their players averaged, not by roster size', function () {
        $small = TelegramTeam::factory()->create(['chat_id' => -100200300, 'name' => 'الزاهر']);
        $big = TelegramTeam::factory()->create(['chat_id' => -100200300, 'name' => 'العابدية']);

        teamPlayer(201, 'أول', 30, $small);
        teamPlayer(202, 'ثاني', 30, $small);
        teamPlayer(203, 'ثالث', 30, $small);

        teamPlayer(301, 'رابع', 10, $big);
        teamPlayer(302, 'خامس', 10, $big);
        teamPlayer(303, 'سادس', 10, $big);
        // Two more on the roster who did not play: they neither help nor hurt.
        TelegramTeamMember::factory()->count(2)->for($big, 'team')->create();

        $api = new FakeTelegramApi;
        (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('المتصدرين', userId: 999));

        $text = withoutBidi($api->sentMessages[0]['text']);

        expect($text)->toContain('🛡️ <b>الفرق هذا الأسبوع</b>')
            ->toContain('🥇 الزاهر — معدل 30 نقطة · شارك 3 من 3')
            ->toContain('🥈 العابدية — معدل 10 نقاط · شارك 3 من 5');
    });

    it('ranks a team however few of its members played', function () {
        $many = TelegramTeam::factory()->create(['chat_id' => -100200300, 'name' => 'الزاهر']);
        $one = TelegramTeam::factory()->create(['chat_id' => -100200300, 'name' => 'العزيزية']);

        teamPlayer(201, 'أول', 10, $many);
        teamPlayer(202, 'ثاني', 10, $many);
        teamPlayer(203, 'ثالث', 10, $many);

        teamPlayer(401, 'بطل', 500, $one);

        $text = withoutBidi(leaderboardText());

        expect($text)->toContain('🥇 العزيزية — معدل 500 نقطة · شارك 1 من 1')
            ->toContain('🥈 الزاهر — معدل 10 نقاط · شارك 3 من 3');
    });

    it('invites the first team to open its account when none has played', function () {
        $team = TelegramTeam::factory()->create(['chat_id' => -100200300, 'name' => 'الزاهر']);
        TelegramTeamMember::factory()->count(3)->for($team, 'team')->create();
        teamPlayer(999, 'وحيد', 10);

        expect(withoutBidi(leaderboardText()))
            ->toContain('لم يسجّل أي فريق نقاطاً هذا الأسبوع بعد')
            ->toContain('أجب على سؤال اليوم');
    });

    it('closes with the join invitation wherever the chat has teams', function () {
        TelegramTeam::factory()->create(['chat_id' => -100200300, 'name' => 'الزاهر']);
        teamPlayer(201, 'أول', 10);

        $api = new FakeTelegramApi;
        (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('المتصدرين', userId: 999));

        expect(withoutBidi($api->sentMessages[0]['text']))->toContain('أرسل: '.QuizLeaderboardHandler::JOIN_COMMAND);
    });

    it('says nothing about teams in a chat that has none', function () {
        teamPlayer(201, 'أول', 10);

        $api = new FakeTelegramApi;
        (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('المتصدرين', userId: 999));

        $text = withoutBidi($api->sentMessages[0]['text']);

        expect($text)->not->toContain('الفرق هذا الأسبوع')
            ->not->toContain(QuizLeaderboardHandler::JOIN_COMMAND);
    });
});

it('collapses each board into an expandable quote and fences its lines', function () {
    QuizAnswer::factory()
        ->for(QuizPlayer::factory()->create(['first_name' => 'Ahmad_99', 'answers_count' => 1]), 'player')
        ->onQuizDate(today())
        ->create(['points' => 40]);

    $api = new FakeTelegramApi;
    (new QuizLeaderboardHandler($api))->handle(leaderboardMessage('المتصدرين', userId: 999));

    $text = $api->sentMessages[0]['text'];

    expect($text)->toContain('<blockquote expandable>')
        ->toContain('</blockquote>')
        // Every line opens right-to-left, and the Latin name is isolated so it
        // cannot drag the rank or the score around it.
        ->toContain(Bidi::RLM.'🥇 ')
        ->toContain(Bidi::isolate('Ahmad_99'));
});
