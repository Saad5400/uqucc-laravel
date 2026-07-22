<?php

use App\Helpers\ArabicPlural;

it('applies Arabic number agreement to counted nouns', function (int $count, string $expected) {
    expect(ArabicPlural::points($count))->toBe($expected);
})->with([
    'zero uses singular' => [0, '0 نقطة'],
    'one' => [1, 'نقطة واحدة'],
    'two uses dual' => [2, 'نقطتان'],
    'three uses plural' => [3, '3 نقاط'],
    'ten uses plural' => [10, '10 نقاط'],
    'eleven back to singular' => [11, '11 نقطة'],
    'ninety nine singular' => [99, '99 نقطة'],
    'hundred singular' => [100, '100 نقطة'],
]);

it('agrees days, people and answers', function () {
    expect(ArabicPlural::days(1))->toBe('يوم واحد')
        ->and(ArabicPlural::days(2))->toBe('يومان')
        ->and(ArabicPlural::days(5))->toBe('5 أيام')
        ->and(ArabicPlural::days(15))->toBe('15 يوم')
        ->and(ArabicPlural::people(1))->toBe('مشارك واحد')
        ->and(ArabicPlural::people(7))->toBe('7 مشاركين')
        ->and(ArabicPlural::answers(2))->toBe('إجابتان')
        ->and(ArabicPlural::answers(4))->toBe('4 إجابات');
});
