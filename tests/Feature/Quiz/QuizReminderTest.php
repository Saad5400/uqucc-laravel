<?php

use App\Models\DailyQuiz;
use App\Models\QuizAnswer;
use App\Models\QuizPost;
use App\Models\QuizTopic;
use App\Services\Quiz\QuizReminder;
use App\Settings\QuizSettings;
use Tests\Fakes\FakeTelegramApi;

beforeEach(function () {
    $settings = app(QuizSettings::class);
    $settings->enabled = true;
    $settings->reminders_enabled = true;
    $settings->chat_ids = ['-100200300'];
    $settings->save();

    $this->fake = new FakeTelegramApi;
    $this->app->bind(QuizReminder::class, fn (): QuizReminder => new QuizReminder(app(QuizSettings::class), $this->fake));
});

/** A live quiz with one open post, plus `$answers` recorded answers on it. */
function liveQuizWith(int $answers = 0, array $quizAttributes = []): DailyQuiz
{
    $quiz = DailyQuiz::factory()->posted()->create($quizAttributes);
    QuizAnswer::factory()->count($answers)->create(['daily_quiz_id' => $quiz->id]);

    return $quiz;
}

it('opens the day by quoting how many have answered so far', function () {
    liveQuizWith(answers: 3);

    $this->artisan('quiz:remind opener')->assertExitCode(0);

    $post = DailyQuiz::first()->posts()->first();

    expect($this->fake->sentMessages)->toHaveCount(1);

    $msg = $this->fake->sentMessages[0];

    expect($msg['chat_id'])->toBe($post->chat_id)
        ->and($msg['reply_to_message_id'])->toBe($post->message_id)
        ->and($msg['text'])->toBe('سؤال اليوم نازل، وجاوب عليه 3 مشاركين — وأنت؟');
});

it('re-floats by replying to the poll, taunting with the wrong-answer share', function () {
    $quiz = DailyQuiz::factory()->posted()->create();
    QuizAnswer::factory()->count(2)->wrong()->create(['daily_quiz_id' => $quiz->id]);
    QuizAnswer::factory()->count(1)->create(['daily_quiz_id' => $quiz->id]);

    $this->artisan('quiz:remind refloat')->assertExitCode(0);

    // 2 of 3 answers wrong -> 67%.
    expect($this->fake->sentMessages)->toHaveCount(1)
        ->and($this->fake->sentMessages[0]['text'])->toBe('سؤال اليوم غلطوا فيه 67%، بتقدر عليه؟');
});

it('re-floats regardless of how high the turnout is', function () {
    $quiz = DailyQuiz::factory()->posted()->create();
    QuizAnswer::factory()->count(30)->wrong()->create(['daily_quiz_id' => $quiz->id]);
    QuizAnswer::factory()->count(10)->create(['daily_quiz_id' => $quiz->id]);

    $this->artisan('quiz:remind refloat')->assertExitCode(0);

    // 30 of 40 answers wrong -> 75%.
    expect($this->fake->sentMessages)->toHaveCount(1)
        ->and($this->fake->sentMessages[0]['text'])->toBe('سؤال اليوم غلطوا فيه 75%، بتقدر عليه؟');
});

it('sends the night nudge without needing any turnout', function () {
    liveQuizWith(answers: 0);

    $this->artisan('quiz:remind night')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(1)
        ->and($this->fake->sentMessages[0]['text'])->toBe('قبل ما تنام 🌙 سؤال اليوم لسه مفتوح');
});

it('re-floats in the morning with the share that got it right', function () {
    $quiz = DailyQuiz::factory()->posted()->create();
    QuizAnswer::factory()->count(3)->create(['daily_quiz_id' => $quiz->id]);
    QuizAnswer::factory()->count(1)->wrong()->create(['daily_quiz_id' => $quiz->id]);

    $this->artisan('quiz:remind morning')->assertExitCode(0);

    // 3 of 4 answers correct -> 75%.
    expect($this->fake->sentMessages)->toHaveCount(1)
        ->and($this->fake->sentMessages[0]['text'])->toBe('صباح الخير ☕ نسبة الإجابات الصحيحة في سؤال اليوم 75% — تقدر ترفعها؟');
});

it('sends the subtle hint mid-window', function () {
    liveQuizWith(answers: 0, quizAttributes: ['hint' => 'فكّر في وحدات القياس.']);

    $this->artisan('quiz:remind hint')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(1)
        ->and($this->fake->sentMessages[0]['text'])->toBe('تلميح لسؤال اليوم: فكّر في وحدات القياس.');
});

it('always sends the closes-soon nudge and includes the obvious hint', function () {
    liveQuizWith(answers: 200, quizAttributes: [
        'hint' => 'فكّر في وحدات القياس.',
        'obvious_hint' => 'العلاقة قسمة على 8.',
    ]);

    $this->artisan('quiz:remind lastcall')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(1)
        ->and($this->fake->sentMessages[0]['text'])->toBe('قرب يقفل سؤال اليوم، تلميح: العلاقة قسمة على 8.');
});

it('falls back to the subtle hint in the closes-soon nudge when a quiz predates the obvious one', function () {
    liveQuizWith(answers: 1, quizAttributes: [
        'hint' => 'فكّر في وحدات القياس.',
        'obvious_hint' => null,
    ]);

    $this->artisan('quiz:remind lastcall')->assertExitCode(0);

    expect($this->fake->sentMessages[0]['text'])->toBe('قرب يقفل سؤال اليوم، تلميح: فكّر في وحدات القياس.');
});

it('omits the hint line when the quiz has neither hint', function () {
    liveQuizWith(answers: 1, quizAttributes: ['hint' => null, 'obvious_hint' => null]);

    $this->artisan('quiz:remind lastcall')->assertExitCode(0);

    expect($this->fake->sentMessages[0]['text'])->toBe('قرب يقفل سؤال اليوم');
});

it('kicks the day off without needing any turnout', function () {
    liveQuizWith(answers: 0);

    $this->artisan('quiz:remind kickoff')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(1)
        ->and($this->fake->sentMessages[0]['text'])->toBe('سؤال اليوم مفتوح 🎯 خذ لك دقيقة وجاوب');
});

it('teases the topic the question came from', function () {
    $topic = QuizTopic::factory()->create(['name' => 'أساسيات الشبكات']);
    liveQuizWith(answers: 0, quizAttributes: ['quiz_topic_id' => $topic->id]);

    $this->artisan('quiz:remind topic')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(1)
        ->and($this->fake->sentMessages[0]['text'])->toBe('سؤال اليوم من «أساسيات الشبكات» — تحسب نفسك قوي فيه؟');
});

it('stays silent on the topic phase when the quiz carries no topic', function () {
    liveQuizWith(answers: 3, quizAttributes: ['quiz_topic_id' => null]);

    $this->artisan('quiz:remind topic')->assertExitCode(0);

    expect($this->fake->sentMessages)->toBeEmpty();
});

it('quotes the answers that landed in the last hour', function () {
    $quiz = liveQuizWith();
    QuizAnswer::factory()->count(4)->create(['daily_quiz_id' => $quiz->id, 'answered_at' => now()->subMinutes(20)]);
    QuizAnswer::factory()->count(9)->create(['daily_quiz_id' => $quiz->id, 'answered_at' => now()->subHours(5)]);

    $this->artisan('quiz:remind momentum')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(1)
        ->and($this->fake->sentMessages[0]['text'])->toBe('وصلتنا 4 إجابات في آخر ساعة 🔥 لا تتأخر');
});

it('stays silent on the momentum phase after a quiet hour', function () {
    $quiz = liveQuizWith();
    QuizAnswer::factory()->count(6)->create(['daily_quiz_id' => $quiz->id, 'answered_at' => now()->subHours(3)]);

    $this->artisan('quiz:remind momentum')->assertExitCode(0);

    expect($this->fake->sentMessages)->toBeEmpty();
});

it('sends the late-night nudge without needing any turnout', function () {
    liveQuizWith(answers: 0);

    $this->artisan('quiz:remind latenight')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(1)
        ->and($this->fake->sentMessages[0]['text'])->toBe('ساهر؟ 🌜 سؤال اليوم لسه ينتظر إجابتك');
});

it('warns off the wrong option the crowd is falling for', function () {
    $quiz = liveQuizWith(answers: 2);
    QuizAnswer::factory()->count(5)->wrong()->create(['daily_quiz_id' => $quiz->id, 'selected_option' => 3]);
    QuizAnswer::factory()->count(3)->wrong()->create(['daily_quiz_id' => $quiz->id, 'selected_option' => 0]);

    $this->artisan('quiz:remind trap')->assertExitCode(0);

    // The most-picked wrong option took 5 of 10 answers -> 50%.
    expect($this->fake->sentMessages)->toHaveCount(1)
        ->and($this->fake->sentMessages[0]['text'])->toBe('أكثر إجابة غلط في سؤال اليوم اختارها 50% — لا تقع فيها');
});

it('stays silent on the trap phase while every answer is correct', function () {
    liveQuizWith(answers: 4);

    $this->artisan('quiz:remind trap')->assertExitCode(0);

    expect($this->fake->sentMessages)->toBeEmpty();
});

it('always sends the closing buzzer', function () {
    liveQuizWith(answers: 0, quizAttributes: ['hint' => null, 'obvious_hint' => null]);

    $this->artisan('quiz:remind closing')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(1)
        ->and($this->fake->sentMessages[0]['text'])->toBe('آخر فرصة ⏳ سؤال اليوم يقفل بعد شوي');
});

it('stays silent on a turnout phase while nobody has answered yet', function (string $phase) {
    liveQuizWith(answers: 0);

    $this->artisan("quiz:remind {$phase}")->assertExitCode(0);

    expect($this->fake->sentMessages)->toBeEmpty();
})->with(['opener', 'refloat', 'morning']);

it('stays silent on the hint phase when the quiz has no hint', function () {
    liveQuizWith(answers: 5, quizAttributes: ['hint' => null]);

    $this->artisan('quiz:remind hint')->assertExitCode(0);

    expect($this->fake->sentMessages)->toBeEmpty();
});

it('reminds every group the quiz is live in', function () {
    $quiz = liveQuizWith(answers: 2);
    QuizPost::factory()->create(['daily_quiz_id' => $quiz->id, 'chat_id' => -100400500]);

    $this->artisan('quiz:remind lastcall')->assertExitCode(0);

    expect(collect($this->fake->sentMessages)->pluck('chat_id')->sort()->values()->all())
        ->toBe([-100400500, -100200300]);
});

it('does not remind on a closed quiz', function () {
    $quiz = DailyQuiz::factory()->closed()->create();
    QuizAnswer::factory()->create(['daily_quiz_id' => $quiz->id]);

    $this->artisan('quiz:remind lastcall')->assertExitCode(0);

    expect($this->fake->sentMessages)->toBeEmpty();
});

it('stays silent while reminders are disabled', function () {
    $settings = app(QuizSettings::class);
    $settings->reminders_enabled = false;
    $settings->save();

    liveQuizWith(answers: 1);

    $this->artisan('quiz:remind lastcall')->assertExitCode(0);

    expect($this->fake->sentMessages)->toBeEmpty();
});

it('rejects an unknown phase', function () {
    $this->artisan('quiz:remind bogus')->assertExitCode(1);
});
