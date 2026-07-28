<?php

use App\Ai\Chat\AnswerLinkGuard;
use App\Ai\Chat\StreamingAnswerLinkGuard;
use App\Models\Corpus\CorpusDocument;
use App\Models\Page;

beforeEach(function () {
    config()->set('app.url', 'https://uqucc.sb.sa');
});

function guard(): AnswerLinkGuard
{
    return new AnswerLinkGuard;
}

it('keeps a link to a real ai-visible page', function () {
    Page::factory()->create(['slug' => '/alkhtt', 'title' => 'الخطة الدراسية']);

    $answer = 'راجع [الخطة الدراسية](https://uqucc.sb.sa/alkhtt) للتفاصيل.';

    expect(guard()->sanitize($answer))->toBe($answer);
});

it('demotes an invented site link to its label and keeps the sentence readable', function () {
    Page::factory()->create(['slug' => '/alkhtt']);

    $answer = "راجع [صفحة التعريف بالتخصصات](https://uqucc.sb.sa/alta'rif-bialtakhsusat) أولاً.";

    expect(guard()->sanitize($answer))->toBe('راجع صفحة التعريف بالتخصصات أولاً.');
});

it('demotes a link to a page hidden from the ai', function () {
    Page::factory()->hiddenFromAi()->create(['slug' => '/sirri']);

    expect(guard()->sanitize('[سري](https://uqucc.sb.sa/sirri)'))->toBe('سري');
});

it('keeps a document reference url on its own host but demotes an invented sibling', function () {
    CorpusDocument::factory()->create(['reference_url' => 'https://uqucc-majors.sb.sa/ds']);

    $answer = '[علم البيانات](https://uqucc-majors.sb.sa/ds) و[الأمن](https://uqucc-majors.sb.sa/cy)';

    expect(guard()->sanitize($answer))
        ->toBe('[علم البيانات](https://uqucc-majors.sb.sa/ds) والأمن');
});

it('keeps the default /mstnd reference url of a document without an explicit one', function () {
    $document = CorpusDocument::factory()->create(['reference_url' => null]);

    $answer = '[اللائحة]('.$document->referenceUrl().')';

    expect(guard()->sanitize($answer))->toBe($answer);
});

it('leaves genuinely external links alone', function () {
    $answer = 'جرّب [كورسيرا](https://www.coursera.org/learn/ml) و https://roadmap.sh/ai .';

    expect(guard()->sanitize($answer))->toBe($answer);
});

it('accepts a real page link despite cosmetic url differences', function () {
    Page::factory()->create(['slug' => '/adwat/almkafa']);

    expect(guard()->isAllowed('http://www.uqucc.sb.sa/adwat/almkafa/'))->toBeTrue()
        ->and(guard()->isAllowed('/adwat/almkafa'))->toBeTrue()
        ->and(guard()->isAllowed('https://uqucc.sb.sa/adwat/almkafa#alsharh'))->toBeTrue();
});

it('drops an invented bare url but keeps the sentence punctuation', function () {
    Page::factory()->create(['slug' => '/alkhtt']);

    expect(guard()->sanitize('الرابط https://uqucc.sb.sa/wahm.'))->toBe('الرابط .');
});

it('leaves text without links untouched', function () {
    expect(guard()->sanitize('لا يوجد «أفضل تخصص» — القرار يعود لك.'))
        ->toBe('لا يوجد «أفضل تخصص» — القرار يعود لك.');
});

it('streams a valid link through intact no matter how the deltas are split', function () {
    Page::factory()->create(['slug' => '/alkhtt']);

    $answer = 'راجع [الخطة](https://uqucc.sb.sa/alkhtt) اليوم.';

    foreach ([1, 3, 7] as $chunkSize) {
        $streaming = new StreamingAnswerLinkGuard(guard());
        $emitted = '';

        foreach (str_split($answer, $chunkSize) as $chunk) {
            $emitted .= $streaming->push($chunk);
        }

        expect($emitted.$streaming->flush())->toBe($answer);
    }
});

it('never emits an invented link mid-stream, at any chunk boundary', function () {
    Page::factory()->create(['slug' => '/alkhtt']);

    $answer = 'راجع [التخصصات](https://uqucc.sb.sa/wahm) الآن.';

    foreach ([1, 2, 5, 11] as $chunkSize) {
        $streaming = new StreamingAnswerLinkGuard(guard());
        $emitted = '';

        foreach (str_split($answer, $chunkSize) as $chunk) {
            $emitted .= $streaming->push($chunk);
            expect($emitted)->not->toContain('wahm');
        }

        expect($emitted.$streaming->flush())->toBe('راجع التخصصات الآن.');
    }
});

it('does not hold back ordinary brackets that never become a link', function () {
    $streaming = new StreamingAnswerLinkGuard(guard());

    $emitted = $streaming->push('المعدل [تراكمي] وليس فصلياً');

    expect($emitted)->toBe('المعدل [تراكمي] وليس فصلياً')
        ->and($streaming->flush())->toBe('');
});

it('releases an unterminated link candidate when the stream ends', function () {
    $streaming = new StreamingAnswerLinkGuard(guard());

    $emitted = $streaming->push('انظر [الخطة');

    expect($emitted.$streaming->flush())->toBe('انظر [الخطة');
});
