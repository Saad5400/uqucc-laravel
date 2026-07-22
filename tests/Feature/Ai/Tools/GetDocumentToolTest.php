<?php

use App\Ai\Corpus\CorpusSourceType;
use App\Ai\Tools\GetDocumentTool;
use App\Models\Corpus\CorpusDocument;
use App\Models\Corpus\CorpusItem;
use App\Settings\AiSettings;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->save();
});

it('returns the full document text with the id and title footer', function () {
    $document = CorpusDocument::factory()->create([
        'title' => 'لائحة الدراسة والاختبارات',
        'status' => CorpusDocument::STATUS_READY,
        'extracted_markdown' => "# لائحة الدراسة\n\n## المادة العشرون\nيُحرم الطالب من دخول الاختبار النهائي إذا تجاوز غيابه النسبة المقررة.",
    ]);

    $reply = (string) app(GetDocumentTool::class)->handle(new Request(['document' => $document->id]));

    expect($reply)->toContain('## المادة العشرون')
        ->toContain('يُحرم الطالب من دخول الاختبار النهائي')
        ->toContain("document: {$document->id} — لائحة الدراسة والاختبارات")
        ->toContain('رابط المستند (المصدر): '.route('documents.show', $document))
        ->toContain('آخر تحديث: '.$document->fresh()->updated_at->toDateString());
});

it('cites the override url when one is set, hiding the default file route', function () {
    $document = CorpusDocument::factory()->create([
        'title' => 'لائحة الدراسة والاختبارات',
        'status' => CorpusDocument::STATUS_READY,
        'extracted_markdown' => '# لائحة الدراسة',
        'reference_url' => 'https://example.com/official/regulations.pdf',
    ]);

    $reply = (string) app(GetDocumentTool::class)->handle(new Request(['document' => $document->id]));

    expect($reply)->toContain('رابط المستند (المصدر): https://example.com/official/regulations.pdf')
        ->not->toContain(route('documents.show', $document));
});

it('never exposes documents that are not ready', function (string $status) {
    $document = CorpusDocument::factory()->create([
        'title' => 'مستند قيد المعالجة',
        'status' => $status,
        'extracted_markdown' => 'محتوى غير جاهز بعد.',
    ]);

    $reply = (string) app(GetDocumentTool::class)->handle(new Request(['document' => $document->id]));

    expect($reply)->toContain('لم يتم العثور')
        ->not->toContain('محتوى غير جاهز');
})->with([
    'pending' => CorpusDocument::STATUS_PENDING,
    'extracting' => CorpusDocument::STATUS_EXTRACTING,
    'failed' => CorpusDocument::STATUS_FAILED,
]);

it('refuses a ready document whose corpus item is disabled', function () {
    $document = CorpusDocument::factory()->create([
        'title' => 'لائحة معطّلة',
        'status' => CorpusDocument::STATUS_READY,
        'extracted_markdown' => 'محتوى المستند المعطّل الذي يجب ألا يظهر.',
    ]);

    CorpusItem::factory()->disabled()->create([
        'source_type' => CorpusSourceType::Document,
        'source_id' => $document->id,
    ]);

    $reply = (string) app(GetDocumentTool::class)->handle(new Request(['document' => $document->id]));

    expect($reply)->toContain('لم يتم العثور')
        ->not->toContain('محتوى المستند المعطّل');
});

it('reads a ready document whose corpus item is enabled', function () {
    $document = CorpusDocument::factory()->create([
        'title' => 'لائحة مفعّلة',
        'status' => CorpusDocument::STATUS_READY,
        'extracted_markdown' => 'محتوى المستند المفعّل الذي يجب أن يظهر.',
    ]);

    CorpusItem::factory()->create([
        'source_type' => CorpusSourceType::Document,
        'source_id' => $document->id,
    ]);

    $reply = (string) app(GetDocumentTool::class)->handle(new Request(['document' => $document->id]));

    expect($reply)->toContain('محتوى المستند المفعّل');
});

it('answers unknown ids with a not-found message', function () {
    $reply = (string) app(GetDocumentTool::class)->handle(new Request(['document' => 999]));

    expect($reply)->toContain('لم يتم العثور على مستند بالمعرف "999"');
});

it('answers long documents with a table of contents instead of the full text', function () {
    $markdown = collect(range(1, 30))
        ->map(fn (int $i): string => "## المادة رقم {$i}\n".str_repeat("نص المادة رقم {$i} في اللائحة. ", 60))
        ->implode("\n\n");

    $document = CorpusDocument::factory()->create([
        'title' => 'لائحة طويلة',
        'status' => CorpusDocument::STATUS_READY,
        'extracted_markdown' => $markdown,
    ]);

    $reply = (string) app(GetDocumentTool::class)->handle(new Request(['document' => $document->id]));

    expect($reply)->toContain('فهرس المستند (30 قسماً)')
        ->toContain('1. ## المادة رقم 1')
        ->toContain('30. ## المادة رقم 30')
        ->toContain("document: {$document->id}")
        ->not->toContain('نص المادة رقم 7 في اللائحة');
});

it('returns one requested section of a long document verbatim', function () {
    $markdown = collect(range(1, 30))
        ->map(fn (int $i): string => "## المادة رقم {$i}\n".str_repeat("نص المادة رقم {$i} في اللائحة. ", 60))
        ->implode("\n\n");

    $document = CorpusDocument::factory()->create([
        'title' => 'لائحة طويلة',
        'status' => CorpusDocument::STATUS_READY,
        'extracted_markdown' => $markdown,
    ]);

    $reply = (string) app(GetDocumentTool::class)->handle(new Request([
        'document' => $document->id,
        'section' => 7,
    ]));

    expect($reply)->toContain('القسم 7 من 30')
        ->toContain('## المادة رقم 7')
        ->toContain('نص المادة رقم 7 في اللائحة')
        ->toContain("document: {$document->id}")
        ->not->toContain('نص المادة رقم 8 في اللائحة');
});

it('returns a contiguous range of sections when end_section is given', function () {
    $markdown = collect(range(1, 30))
        ->map(fn (int $i): string => "## المادة رقم {$i}\n".str_repeat("نص المادة رقم {$i} في اللائحة. ", 60))
        ->implode("\n\n");

    $document = CorpusDocument::factory()->create([
        'status' => CorpusDocument::STATUS_READY,
        'extracted_markdown' => $markdown,
    ]);

    $reply = (string) app(GetDocumentTool::class)->handle(new Request([
        'document' => $document->id,
        'section' => 5,
        'end_section' => 6,
    ]));

    expect($reply)->toContain('الأقسام 5–6 من 30')
        ->toContain('نص المادة رقم 5 في اللائحة')
        ->toContain('نص المادة رقم 6 في اللائحة')
        ->not->toContain('نص المادة رقم 7 في اللائحة');
});

it('rejects an out-of-range section number with the valid range', function () {
    $markdown = collect(range(1, 30))
        ->map(fn (int $i): string => "## المادة رقم {$i}\n".str_repeat("نص المادة رقم {$i} في اللائحة. ", 60))
        ->implode("\n\n");

    $document = CorpusDocument::factory()->create([
        'status' => CorpusDocument::STATUS_READY,
        'extracted_markdown' => $markdown,
    ]);

    $reply = (string) app(GetDocumentTool::class)->handle(new Request([
        'document' => $document->id,
        'section' => 99,
    ]));

    expect($reply)->toContain('لا يوجد قسم رقم 99')
        ->toContain('من 1 إلى 30')
        ->not->toContain('نص المادة رقم');
});

it('keeps a giant heading-less document fully readable through continuation sections', function () {
    $document = CorpusDocument::factory()->create([
        'status' => CorpusDocument::STATUS_READY,
        'extracted_markdown' => str_repeat('نص طويل جداً من اللائحة. ', 5000),
    ]);

    $tool = app(GetDocumentTool::class);

    $outline = (string) $tool->handle(new Request(['document' => $document->id]));

    expect($outline)->toContain('فهرس المستند')
        ->toContain('… تكملة القسم السابق');

    preg_match('/فهرس المستند \((\d+) قسماً\)/u', $outline, $matches);
    $total = (int) $matches[1];

    expect($total)->toBeGreaterThan(1);

    $lastSection = (string) $tool->handle(new Request([
        'document' => $document->id,
        'section' => $total,
    ]));

    expect($lastSection)->toContain("القسم {$total} من {$total}")
        ->toContain('نص طويل جداً من اللائحة.');
});

it('caps an oversized section range and says how to get the rest', function () {
    $markdown = collect(range(1, 30))
        ->map(fn (int $i): string => "## المادة رقم {$i}\n".str_repeat("نص المادة رقم {$i} في اللائحة. ", 60))
        ->implode("\n\n");

    $document = CorpusDocument::factory()->create([
        'status' => CorpusDocument::STATUS_READY,
        'extracted_markdown' => $markdown,
    ]);

    $reply = (string) app(GetDocumentTool::class)->handle(new Request([
        'document' => $document->id,
        'section' => 1,
        'end_section' => 30,
    ]));

    expect(mb_strlen($reply))->toBeLessThan(32000)
        ->and($reply)->toContain('اطلب أقساماً أقل');
});

it('refuses politely while the master ai switch is off', function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = false;
    $settings->save();

    $document = CorpusDocument::factory()->create([
        'status' => CorpusDocument::STATUS_READY,
        'extracted_markdown' => 'سر لا يظهر.',
    ]);

    $reply = (string) app(GetDocumentTool::class)->handle(new Request(['document' => $document->id]));

    expect($reply)->not->toContain('سر لا يظهر');
});
