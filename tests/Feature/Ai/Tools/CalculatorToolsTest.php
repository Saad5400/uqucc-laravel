<?php

use App\Ai\Tools\CalculateDeprivationTool;
use App\Ai\Tools\CalculateGpaTool;
use App\Ai\Tools\CalculateTransferTool;
use App\Settings\AiSettings;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->save();
});

it('calculates a gpa from a course list', function () {
    $reply = (string) app(CalculateGpaTool::class)->handle(new Request([
        'courses' => [
            ['credits' => '3', 'grade' => 'A+'],
            ['credits' => '3', 'grade' => 'B'],
        ],
    ]));

    expect($reply)->toContain('3.5')
        ->toContain('6')
        ->toContain('21');
});

it('accepts numeric credits and lowercase grades', function () {
    $reply = (string) app(CalculateGpaTool::class)->handle(new Request([
        'courses' => [
            ['credits' => 3, 'grade' => 'a+'],
        ],
    ]));

    expect($reply)->toContain('4');
});

it('asks for courses when the gpa list is empty', function () {
    $reply = (string) app(CalculateGpaTool::class)->handle(new Request(['courses' => []]));

    expect($reply)->toContain('يرجى إدخال قائمة المقررات');
});

it('reports when no gpa row could be counted', function () {
    $reply = (string) app(CalculateGpaTool::class)->handle(new Request([
        'courses' => [
            ['credits' => '0', 'grade' => 'A+'],
            ['credits' => '3', 'grade' => 'Z'],
        ],
    ]));

    expect($reply)->toContain('لم يتم احتساب أي مقرر');
});

it('calculates deprivation limits', function () {
    $reply = (string) app(CalculateDeprivationTool::class)->handle(new Request([
        'lectures_per_week' => 2,
        'unexcused_hours' => 4,
        'excused_hours' => 4,
    ]));

    expect($reply)->toContain('غير محروم')
        ->toContain('34')
        ->toContain('23.53');
});

it('flags a deprived student', function () {
    $reply = (string) app(CalculateDeprivationTool::class)->handle(new Request([
        'lectures_per_week' => 2,
        'unexcused_hours' => 6,
    ]));

    expect($reply)->toContain('محروم')
        ->toContain('DEPRIVED');
});

it('rejects invalid deprivation input', function (array $arguments, string $message) {
    $reply = (string) app(CalculateDeprivationTool::class)->handle(new Request($arguments));

    expect($reply)->toContain($message);
})->with([
    'missing lectures_per_week' => [[], 'ساعات المقرر'],
    'zero lectures_per_week' => [['lectures_per_week' => 0], 'ساعات المقرر'],
    'negative absence hours' => [['lectures_per_week' => 2, 'unexcused_hours' => -1], 'سالبة'],
]);

it('calculates the transfer composite score', function () {
    $reply = (string) app(CalculateTransferTool::class)->handle(new Request([
        'weighted_score' => 80,
        'cumulative_gpa' => 4,
    ]));

    expect($reply)->toContain('90');
});

it('supports a custom transfer percentage split', function () {
    $reply = (string) app(CalculateTransferTool::class)->handle(new Request([
        'weighted_score' => 80,
        'cumulative_gpa' => 4,
        'weighted_percentage' => 70,
        'gpa_percentage' => 30,
    ]));

    expect($reply)->toContain('86');
});

it('rejects non-positive transfer inputs', function () {
    $reply = (string) app(CalculateTransferTool::class)->handle(new Request([
        'weighted_score' => 0,
        'cumulative_gpa' => 4,
    ]));

    expect($reply)->toContain('أكبر من صفر');
});

it('returns a disabled message from every calculator when the master ai kill switch is off', function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = false;
    $settings->save();

    $gpa = (string) app(CalculateGpaTool::class)->handle(new Request([
        'courses' => [['credits' => '3', 'grade' => 'A+']],
    ]));
    $deprivation = (string) app(CalculateDeprivationTool::class)->handle(new Request(['lectures_per_week' => 2]));
    $transfer = (string) app(CalculateTransferTool::class)->handle(new Request([
        'weighted_score' => 80,
        'cumulative_gpa' => 4,
    ]));

    expect($gpa)->toContain('معطلة')
        ->and($deprivation)->toContain('معطلة')
        ->and($transfer)->toContain('معطلة');
});
