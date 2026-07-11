<?php

use App\Ai\Tools\GetDocumentTool;
use App\Models\Corpus\CorpusDocument;
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
        ->toContain('آخر تحديث: '.$document->fresh()->updated_at->toDateString());
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

it('answers unknown ids with a not-found message', function () {
    $reply = (string) app(GetDocumentTool::class)->handle(new Request(['document' => 999]));

    expect($reply)->toContain('لم يتم العثور على مستند بالمعرف "999"');
});

it('truncates very long documents but keeps the footer', function () {
    $document = CorpusDocument::factory()->create([
        'title' => 'مستند ضخم',
        'status' => CorpusDocument::STATUS_READY,
        'extracted_markdown' => str_repeat('نص طويل جداً من اللائحة. ', 5000),
    ]);

    $reply = (string) app(GetDocumentTool::class)->handle(new Request(['document' => $document->id]));

    expect(mb_strlen($reply))->toBeLessThan(62000)
        ->and($reply)->toContain('[اقتُطع باقي المستند لطوله]')
        ->and($reply)->toContain("document: {$document->id}");
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
