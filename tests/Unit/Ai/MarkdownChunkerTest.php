<?php

use App\Ai\Corpus\ChunkDraft;
use App\Ai\Corpus\MarkdownChunker;

it('returns no chunks for empty or whitespace-only text', function () {
    $chunker = new MarkdownChunker;

    expect($chunker->chunk(''))->toBe([])
        ->and($chunker->chunk("   \n\t  "))->toBe([]);
});

it('produces a single heading-less chunk for short plain text', function () {
    $chunker = new MarkdownChunker;

    $chunks = $chunker->chunk('نص قصير بدون عناوين');

    expect($chunks)->toHaveCount(1)
        ->and($chunks[0])->toBeInstanceOf(ChunkDraft::class)
        ->and($chunks[0]->heading)->toBeNull()
        ->and($chunks[0]->content)->toBe('نص قصير بدون عناوين');
});

it('splits sections on markdown headings and tags each chunk with its heading', function () {
    $chunker = new MarkdownChunker;

    $markdown = implode("\n", [
        '# الخطة الدراسية',
        '',
        'مقدمة عن الخطة',
        '',
        '## المقررات الإجبارية',
        '',
        'يتضمن القسم مقررات البرمجة والرياضيات',
        '',
        '## المقررات الاختيارية',
        '',
        'يمكن للطالب اختيار مقررات حرة',
    ]);

    $chunks = $chunker->chunk($markdown);

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0]->heading)->toBe('الخطة الدراسية')
        ->and($chunks[0]->content)->toBe('مقدمة عن الخطة')
        ->and($chunks[1]->heading)->toBe('المقررات الإجبارية')
        ->and($chunks[1]->content)->toContain('البرمجة')
        ->and($chunks[2]->heading)->toBe('المقررات الاختيارية')
        ->and($chunks[2]->content)->toContain('اختيار');
});

it('keeps text before the first heading as a heading-less chunk', function () {
    $chunker = new MarkdownChunker;

    $chunks = $chunker->chunk("intro paragraph\n\n## Section\n\nsection body");

    expect($chunks)->toHaveCount(2)
        ->and($chunks[0]->heading)->toBeNull()
        ->and($chunks[0]->content)->toBe('intro paragraph')
        ->and($chunks[1]->heading)->toBe('Section');
});

it('windows long sections with the configured overlap', function () {
    $chunker = new MarkdownChunker(maxWords: 10, overlapWords: 3);

    $words = array_map(fn (int $i): string => "w{$i}", range(1, 24));
    $chunks = $chunker->chunk(implode(' ', $words));

    // Stride is 10 - 3 = 7 words: windows start at w1, w8, and w15 (the
    // last window reaches the end of the section, so iteration stops).
    expect($chunks)->toHaveCount(3)
        ->and($chunks[0]->content)->toBe('w1 w2 w3 w4 w5 w6 w7 w8 w9 w10')
        ->and($chunks[1]->content)->toBe('w8 w9 w10 w11 w12 w13 w14 w15 w16 w17')
        ->and($chunks[2]->content)->toBe('w15 w16 w17 w18 w19 w20 w21 w22 w23 w24');
});

it('never splits an arabic word across chunks', function () {
    $chunker = new MarkdownChunker(maxWords: 4, overlapWords: 1);

    $original = ['الجامعة', 'تقدم', 'مقررات', 'متنوعة', 'في', 'علوم', 'الحاسب', 'والبرمجة'];
    $chunks = $chunker->chunk(implode(' ', $original));

    foreach ($chunks as $chunk) {
        $words = explode(' ', $chunk->content);

        foreach ($words as $word) {
            expect($original)->toContain($word);
        }
    }
});

it('is deterministic for identical input', function () {
    $chunker = new MarkdownChunker(maxWords: 8, overlapWords: 2);
    $markdown = "## عنوان\n\n".implode(' ', array_fill(0, 30, 'كلمة'));

    expect($chunker->chunk($markdown))->toEqual($chunker->chunk($markdown));
});

it('estimates tokens as unicode word count', function () {
    $chunker = new MarkdownChunker;

    expect($chunker->estimateTokens('البرمجة ممتعة جدا'))->toBe(3)
        ->and($chunker->estimateTokens(''))->toBe(0)
        ->and($chunker->estimateTokens("one\ttwo\nthree"))->toBe(3);
});

it('prepends the heading to the embedding text but not to the content', function () {
    $draft = new ChunkDraft('القبول', 'شروط التسجيل في الجامعة');

    expect($draft->embeddingText())->toBe("القبول\n\nشروط التسجيل في الجامعة")
        ->and($draft->content)->toBe('شروط التسجيل في الجامعة');

    $headingless = new ChunkDraft(null, 'نص');

    expect($headingless->embeddingText())->toBe('نص');
});
