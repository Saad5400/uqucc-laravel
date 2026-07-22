<?php

use App\Models\DailyQuiz;
use App\Models\QuizAnswer;
use App\Models\QuizPost;
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

it('re-floats a low-turnout quiz by replying to the poll, taunting with the wrong-answer share', function () {
    $quiz = DailyQuiz::factory()->posted()->create();
    QuizAnswer::factory()->count(2)->wrong()->create(['daily_quiz_id' => $quiz->id]);
    QuizAnswer::factory()->count(1)->create(['daily_quiz_id' => $quiz->id]);
    $post = $quiz->posts()->first();

    $this->artisan('quiz:remind refloat')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(1);

    $msg = $this->fake->sentMessages[0];

    // 2 of 3 answers wrong -> 67%.
    expect($msg['chat_id'])->toBe($post->chat_id)
        ->and($msg['reply_to_message_id'])->toBe($post->message_id)
        ->and($msg['text'])->toBe('سؤال اليوم غلطوا فيه 67%، بتقدر عليه؟');
});

it('stays silent on the re-float once turnout is healthy', function () {
    liveQuizWith(answers: QuizReminder::REFLOAT_MAX_PARTICIPANTS);

    $this->artisan('quiz:remind refloat')->assertExitCode(0);

    expect($this->fake->sentMessages)->toBeEmpty();
});

it('always sends the last call and includes the hint', function () {
    liveQuizWith(answers: 200, quizAttributes: ['hint' => 'فكّر في وحدات القياس.']);

    $this->artisan('quiz:remind lastcall')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(1)
        ->and($this->fake->sentMessages[0]['text'])->toBe('آخر فرصة في سؤال اليوم، تلميح: فكّر في وحدات القياس.');
});

it('re-floats with a first-timer taunt when nobody has answered yet', function () {
    liveQuizWith(answers: 0);

    $this->artisan('quiz:remind refloat')->assertExitCode(0);

    expect($this->fake->sentMessages)->toHaveCount(1)
        ->and($this->fake->sentMessages[0]['text'])->toContain('بتقدر عليه؟');
});

it('omits the hint line when the quiz has none', function () {
    liveQuizWith(answers: 1, quizAttributes: ['hint' => null]);

    $this->artisan('quiz:remind lastcall')->assertExitCode(0);

    expect($this->fake->sentMessages[0]['text'])->not->toContain('تلميح');
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
