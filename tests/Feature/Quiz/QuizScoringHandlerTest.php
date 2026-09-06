<?php

use App\Helpers\Bidi;
use App\Services\Quiz\QuizAnswerRecorder;
use App\Services\Quiz\QuizLeaderboard;
use App\Services\Telegram\Handlers\QuizScoringHandler;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Objects\Message;
use Tests\Fakes\FakeTelegramApi;

beforeEach(fn () => Cache::flush());

function scoringMessage(string $text, int $chatId = -100200300): Message
{
    return new Message([
        'message_id' => 10,
        'text' => $text,
        'chat' => ['id' => $chatId, 'type' => 'supergroup'],
        'from' => ['id' => 111, 'is_bot' => false, 'first_name' => 'سعد'],
    ]);
}

function scoringText(string $trigger = 'نقاط سؤال اليوم'): string
{
    $api = new FakeTelegramApi;
    (new QuizScoringHandler($api))->handle(scoringMessage($trigger));

    return withoutBidi($api->sentMessages[0]['text']);
}

it('answers with the whole scoring system', function (string $trigger) {
    $text = scoringText($trigger);

    expect($text)->toContain('نقاط سؤال اليوم')
        ->toContain('إجابة صحيحة: 10 نقاط')
        ->toContain('إجابة خاطئة: نقطتان')
        ->toContain('+1 لكل يوم متتالٍ، حتى +12')
        ->toContain('أسرع 5 إجابات صحيحة تأخذ +25 · +18 · +12 · +7 · +3')
        ->toContain('أعلى يوم ممكن: 47 نقطة')
        ->toContain('لوحة الأسبوع تبدأ يوم الخميس')
        ->toContain('آخر 30 يوماً')
        ->toContain('نقاطي')
        ->toContain('المتصدرين');
})->with(['نقاط سؤال اليوم', 'نظام النقاط', '/scoring', '/scoring@UquccTestBot']);

it('reads every number from the scoring itself', function () {
    $text = scoringText();

    expect($text)
        ->toContain((string) QuizAnswerRecorder::POINTS_CORRECT)
        ->toContain('+'.QuizAnswerRecorder::STREAK_BONUS_CAP)
        ->toContain('+'.QuizAnswerRecorder::SPEED_BONUSES[0])
        ->toContain((string) QuizLeaderboard::WINDOW_DAYS);
});

it('spells out the streak freeze and its cooldown', function () {
    expect(scoringText())
        ->toContain('تفويت سؤال واحد كل 7 أيام')
        ->toContain('سؤالين متتاليين');
});

it('says how a team is scored', function () {
    expect(scoringText())->toContain('بمعدل نقاط من شارك من أعضائه');
});

it('states the rules without arguing for them', function () {
    $text = scoringText();

    // The card is a reference, not an explanation: no clause here says why a
    // number is what it is.
    expect($text)->not->toContain('فلا')
        ->not->toContain('لأن')
        ->not->toContain('حتى لا')
        ->and(substr_count($text, "\n"))->toBeLessThanOrEqual(12);
});

it('fences the message so the numbers keep their direction', function () {
    $api = new FakeTelegramApi;
    (new QuizScoringHandler($api))->handle(scoringMessage('نقاط سؤال اليوم'));

    expect($api->sentMessages[0]['text'])->toContain(Bidi::RLM)
        ->and($api->sentMessages[0]['parse_mode'])->toBe('HTML');
});

it('rate-limits repeated cards in the same chat', function () {
    $api = new FakeTelegramApi;
    $handler = new QuizScoringHandler($api);

    $handler->handle(scoringMessage('نقاط سؤال اليوم'));
    $handler->handle(scoringMessage('نقاط سؤال اليوم'));

    expect($api->sentMessages)->toHaveCount(1);

    $handler->handle(scoringMessage('نقاط سؤال اليوم', chatId: -100999888));

    expect($api->sentMessages)->toHaveCount(2);
});

it('ignores unrelated messages', function (string $text) {
    $api = new FakeTelegramApi;
    (new QuizScoringHandler($api))->handle(scoringMessage($text));

    expect($api->sentMessages)->toBeEmpty();
})->with(['نقاطي', 'المتصدرين', 'كم نقاط سؤال اليوم عندك؟']);
