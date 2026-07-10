<?php

use App\Ai\Copilot\TipTapContent;

it('flattens a tiptap document to markdown', function () {
    $markdown = TipTapContent::toMarkdown([
        'type' => 'doc',
        'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'عنوان']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'فقرة.']]],
        ],
    ]);

    expect($markdown)->toBe("## عنوان\n\nفقرة.");
});

it('flattens legacy string content to trimmed markdown', function () {
    expect(TipTapContent::toMarkdown("  نص قديم  \n"))->toBe('نص قديم')
        ->and(TipTapContent::toMarkdown(null))->toBe('');
});

it('converts markdown to a tiptap document', function () {
    $document = TipTapContent::toDocument("## عنوان\n\nفقرة **مهمة**.\n\n- بند أول\n- بند ثانٍ");

    expect($document['type'])->toBe('doc')
        ->and($document['content'][0]['type'])->toBe('heading')
        ->and($document['content'][0]['attrs']['level'])->toBe(2)
        ->and($document['content'][1]['type'])->toBe('paragraph')
        ->and($document['content'][2]['type'])->toBe('bulletList');
});

it('wraps list item inline content in paragraph nodes', function () {
    $document = TipTapContent::toDocument("- بند أول\n- بند ثانٍ");

    foreach ($document['content'][0]['content'] as $listItem) {
        expect($listItem['type'])->toBe('listItem')
            ->and($listItem['content'][0]['type'])->toBe('paragraph');
    }
});

it('appends markdown blocks after existing document content', function () {
    $current = [
        'type' => 'doc',
        'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'محتوى موجود.']]],
        ],
    ];

    $document = TipTapContent::append($current, "## قسم جديد\n\nمحتوى القسم.");

    expect($document['content'][0]['content'][0]['text'])->toBe('محتوى موجود.')
        ->and($document['content'][1]['type'])->toBe('heading')
        ->and(json_encode($document, JSON_UNESCAPED_UNICODE))->toContain('قسم جديد');
});

it('appends onto empty or missing content', function () {
    $document = TipTapContent::append(null, '## قسم جديد');

    expect($document['type'])->toBe('doc')
        ->and($document['content'][0]['type'])->toBe('heading');
});

it('appends onto legacy string content', function () {
    $document = TipTapContent::append('<p>نص قديم</p>', '## قسم جديد');

    $json = json_encode($document, JSON_UNESCAPED_UNICODE);

    expect($json)->toContain('نص قديم')
        ->and($json)->toContain('قسم جديد');
});
