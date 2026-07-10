<?php

use App\Ai\Corpus\CorpusSourceType;
use App\Ai\Corpus\DocumentExtractionAgent;
use App\Ai\Corpus\IngestDocument;
use App\Jobs\Ai\ExtractCorpusDocumentJob;
use App\Models\Corpus\CorpusChunk;
use App\Models\Corpus\CorpusDocument;
use App\Models\Corpus\CorpusItem;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(CorpusDocument::DISK);

    config()->set('ai.embeddings.driver', 'fake');
    config()->set('ai.embeddings.dimensions', 64);
});

function enableDocumentAiSearch(): void
{
    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->search_enabled = true;
    $settings->save();
}

function enableDocumentVision(): void
{
    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->save();

    config()->set('ai.providers.openrouter.key', 'test-key');
}

function makeCorpusDocument(string $fixture, string $mime, array $overrides = []): CorpusDocument
{
    $path = CorpusDocument::DIRECTORY.'/'.basename($fixture);

    Storage::disk(CorpusDocument::DISK)->put(
        $path,
        file_get_contents(base_path('tests/Fixtures/'.$fixture))
    );

    return CorpusDocument::factory()->create([
        'original_filename' => basename($fixture),
        'path' => $path,
        'mime' => $mime,
        ...$overrides,
    ]);
}

function makeCorpusImageDocument(array $overrides = []): CorpusDocument
{
    $path = CorpusDocument::DIRECTORY.'/scan.png';

    Storage::disk(CorpusDocument::DISK)->put($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    ));

    return CorpusDocument::factory()->image()->create([
        'original_filename' => 'scan.png',
        'path' => $path,
        ...$overrides,
    ]);
}

function documentCorpusItem(CorpusDocument $document): ?CorpusItem
{
    return CorpusItem::query()
        ->where('source_type', CorpusSourceType::Document)
        ->where('source_id', $document->id)
        ->first();
}

describe('extraction job', function () {
    it('extracts the text layer from a born-digital PDF without calling the vision model', function () {
        enableDocumentAiSearch();
        DocumentExtractionAgent::fake();

        $document = makeCorpusDocument('text-layer.pdf', 'application/pdf');

        ExtractCorpusDocumentJob::dispatch($document->id);

        $document->refresh();

        expect($document->status)->toBe(CorpusDocument::STATUS_READY)
            ->and($document->extracted_markdown)->toContain('Article one')
            ->and($document->error)->toBeNull();

        $item = documentCorpusItem($document);

        expect($item)->not->toBeNull()
            ->and($item->status)->toBe(CorpusItem::STATUS_READY)
            ->and($item->title)->toBe($document->title)
            ->and($item->chunks()->exists())->toBeTrue()
            ->and($item->chunks()->first()->embedding)->not->toBeNull();

        DocumentExtractionAgent::assertNeverPrompted();
    });

    it('reads a plain-text upload directly, normalizing line endings, without any AI call', function () {
        enableDocumentAiSearch();
        DocumentExtractionAgent::fake();

        $path = CorpusDocument::DIRECTORY.'/notes.txt';

        Storage::disk(CorpusDocument::DISK)->put(
            $path,
            "\u{FEFF}شروط القبول في الجامعة\r\nيجب على الطالب إكمال جميع المتطلبات المذكورة في اللائحة."
        );

        $document = CorpusDocument::factory()->create([
            'original_filename' => 'notes.txt',
            'path' => $path,
            'mime' => 'text/plain',
        ]);

        ExtractCorpusDocumentJob::dispatch($document->id);

        $document->refresh();

        expect($document->status)->toBe(CorpusDocument::STATUS_READY)
            ->and($document->extracted_markdown)->toBe("شروط القبول في الجامعة\nيجب على الطالب إكمال جميع المتطلبات المذكورة في اللائحة.")
            ->and($document->error)->toBeNull()
            ->and(documentCorpusItem($document)?->chunks()->exists())->toBeTrue();

        DocumentExtractionAgent::assertNeverPrompted();
    });

    it('round-trips a markdown upload: the file contents become the extracted markdown as-is', function () {
        enableDocumentAiSearch();
        DocumentExtractionAgent::fake();

        $markdown = "## لائحة الدراسة\n\n- المادة الأولى: تفاصيل كاملة عن نظام الدراسة.\n- المادة الثانية: تفاصيل الاختبارات.";
        $path = CorpusDocument::DIRECTORY.'/regulation.md';

        Storage::disk(CorpusDocument::DISK)->put($path, $markdown);

        $document = CorpusDocument::factory()->create([
            'original_filename' => 'regulation.md',
            'path' => $path,
            'mime' => 'text/markdown',
        ]);

        ExtractCorpusDocumentJob::dispatch($document->id);

        $document->refresh();

        expect($document->status)->toBe(CorpusDocument::STATUS_READY)
            ->and($document->extracted_markdown)->toBe($markdown)
            ->and(documentCorpusItem($document))->not->toBeNull();

        DocumentExtractionAgent::assertNeverPrompted();
    });

    it('marks a text document failed when the file is empty', function () {
        enableDocumentAiSearch();
        DocumentExtractionAgent::fake();

        $path = CorpusDocument::DIRECTORY.'/empty.txt';

        Storage::disk(CorpusDocument::DISK)->put($path, "  \r\n\t ");

        $document = CorpusDocument::factory()->create([
            'original_filename' => 'empty.txt',
            'path' => $path,
            'mime' => 'text/plain',
        ]);

        ExtractCorpusDocumentJob::dispatch($document->id);

        $document->refresh();

        expect($document->status)->toBe(CorpusDocument::STATUS_FAILED)
            ->and($document->error)->toContain('لم يُستخرج أي نص');

        DocumentExtractionAgent::assertNeverPrompted();
    });

    it('falls back to the vision model for a scanned PDF, attaching the PDF as a document', function () {
        enableDocumentAiSearch();
        enableDocumentVision();
        DocumentExtractionAgent::fake(["## لائحة الدراسة\n\nيجب على الطالب إكمال جميع المتطلبات."]);

        $document = makeCorpusDocument('scanned.pdf', 'application/pdf');

        ExtractCorpusDocumentJob::dispatch($document->id);

        $document->refresh();

        expect($document->status)->toBe(CorpusDocument::STATUS_READY)
            ->and($document->extracted_markdown)->toContain('لائحة الدراسة');

        DocumentExtractionAgent::assertPrompted(
            fn ($prompt): bool => str_contains($prompt->prompt, 'scanned.pdf')
        );

        expect(documentCorpusItem($document)?->chunks()->exists())->toBeTrue();
    });

    it('falls back to the vision model for a PDF whose text layer is punctuation soup with no letters', function () {
        enableDocumentAiSearch();
        enableDocumentVision();
        DocumentExtractionAgent::fake(["## الدليل الإرشادي\n\nالمحتوى الحقيقي للمستند المنسوخ بالرؤية."]);

        $document = makeCorpusDocument('junk-layer.pdf', 'application/pdf');

        ExtractCorpusDocumentJob::dispatch($document->id);

        $document->refresh();

        expect($document->status)->toBe(CorpusDocument::STATUS_READY)
            ->and($document->extracted_markdown)->toContain('الدليل الإرشادي')
            ->and($document->extracted_markdown)->not->toContain('- - : ;');

        DocumentExtractionAgent::assertPrompted(
            fn ($prompt): bool => str_contains($prompt->prompt, 'junk-layer.pdf')
        );
    });

    it('judges text-layer usability on letters, order, and shaping', function (string $layer, bool $usable) {
        expect(app(\App\Ai\Corpus\UploadedTextExtractor::class)->isUsableTextLayer($layer))->toBe($usable);
    })->with([
        'long logical arabic' => [str_repeat('يجب على الطالب إكمال جميع المتطلبات الدراسية قبل التخرج. ', 5), true],
        'long english' => [str_repeat('Students must complete all required credit hours. ', 5), true],
        'punctuation soup, no letters' => [str_repeat('- - : ; , . ( ) ­ ', 40), false],
        'mojibake sprinkled in junk (letters clear the floor but not the ratio)' => [str_repeat('ª', 200).str_repeat('­ ( ) : . ', 400), false],
        'too short despite being real' => ['نص قصير جداً', false],
        'arabic presentation-form glyph soup' => [str_repeat('ﻣﻜﺎﻓﺄﺓ ﺍﻟﺘﻔﻮﻕ ﻟﻠﻄﻼﺏ ﺍﻟﻤﺘﻔﻮﻗﻴﻦ ﻓﻲ ﺍﻟﻜﻠﻴﺔ ', 10), false],
        'reversed arabic (visual-order dump)' => [str_repeat('ىرقلا مأ ةعماج يف يبلاطلا طابضنلااو كولسلا دعاوق ةداملا ىلع ءانب ةرداصلا تارابتخلااو ةيعماجلا ةساردلا ةحئلا ', 5), false],
        'mostly logical with a stray shaped char' => [str_repeat('مكافأة التفوق للطلاب المتفوقين في الكلية ', 10).'ﻣ', true],
    ]);

    it('extracts an image upload via the vision model', function () {
        enableDocumentAiSearch();
        enableDocumentVision();
        DocumentExtractionAgent::fake(["## دليل الطالب\n\nمحتوى مستخرج من الصورة."]);

        $document = makeCorpusImageDocument();

        ExtractCorpusDocumentJob::dispatch($document->id);

        $document->refresh();

        expect($document->status)->toBe(CorpusDocument::STATUS_READY)
            ->and($document->extracted_markdown)->toContain('دليل الطالب')
            ->and(documentCorpusItem($document))->not->toBeNull();
    });

    it('marks the document failed and stores the error when vision is unavailable', function () {
        $document = makeCorpusDocument('scanned.pdf', 'application/pdf');

        ExtractCorpusDocumentJob::dispatch($document->id);

        $document->refresh();

        expect($document->status)->toBe(CorpusDocument::STATUS_FAILED)
            ->and($document->error)->toContain('الذكاء الاصطناعي معطل')
            ->and(documentCorpusItem($document))->toBeNull();
    });

    it('marks the document failed for an unsupported file type', function () {
        enableDocumentAiSearch();

        $document = makeCorpusDocument('text-layer.pdf', 'application/zip');

        ExtractCorpusDocumentJob::dispatch($document->id);

        $document->refresh();

        expect($document->status)->toBe(CorpusDocument::STATUS_FAILED)
            ->and($document->error)->toContain('غير مدعوم');
    });

    it('keeps the extracted text but skips embedding while AI search is disabled', function () {
        enableDocumentVision();
        DocumentExtractionAgent::fake(['نص مستخرج بدون فهرسة.']);

        $document = makeCorpusDocument('scanned.pdf', 'application/pdf');

        ExtractCorpusDocumentJob::dispatch($document->id);

        $document->refresh();

        expect($document->status)->toBe(CorpusDocument::STATUS_READY)
            ->and($document->extracted_markdown)->toContain('نص مستخرج')
            ->and(documentCorpusItem($document))->toBeNull()
            ->and(CorpusChunk::query()->count())->toBe(0);
    });
});

describe('document ingestion', function () {
    it('is idempotent: re-ingesting unchanged markdown leaves the chunk rows untouched', function () {
        enableDocumentAiSearch();

        $document = makeCorpusDocument('text-layer.pdf', 'application/pdf', [
            'status' => CorpusDocument::STATUS_READY,
            'extracted_markdown' => "## شروط القبول\n\nشروط القبول في الجامعة وتفاصيلها الكاملة.",
        ]);

        app(IngestDocument::class)->ingest($document);

        $item = documentCorpusItem($document);
        $originalChunkIds = $item->chunks()->pluck('id')->all();
        $originalChecksum = $item->checksum;

        app(IngestDocument::class)->ingest($document->fresh());

        $item->refresh();

        expect($item->checksum)->toBe($originalChecksum)
            ->and($item->chunks()->pluck('id')->all())->toBe($originalChunkIds);
    });

    it('re-chunks when the extracted markdown changes', function () {
        enableDocumentAiSearch();

        $document = makeCorpusDocument('text-layer.pdf', 'application/pdf', [
            'status' => CorpusDocument::STATUS_READY,
            'extracted_markdown' => 'محتوى قديم عن التسجيل.',
        ]);

        app(IngestDocument::class)->ingest($document);

        $item = documentCorpusItem($document);
        $oldChecksum = $item->checksum;

        $document->update(['extracted_markdown' => 'محتوى جديد كلياً عن الحذف والإضافة.']);

        app(IngestDocument::class)->ingest($document->fresh());

        $item->refresh();

        expect($item->checksum)->not->toBe($oldChecksum)
            ->and($item->chunks()->pluck('normalized_content')->join(' '))->toContain('جديد')
            ->and($item->chunks()->pluck('normalized_content')->join(' '))->not->toContain('قديم');
    });

    it('stamps source_updated_at from the document and refreshes it when only the date changes', function () {
        enableDocumentAiSearch();

        $document = makeCorpusDocument('text-layer.pdf', 'application/pdf', [
            'status' => CorpusDocument::STATUS_READY,
            'extracted_markdown' => 'نص المستند لاختبار تاريخ التحديث.',
        ]);

        app(IngestDocument::class)->ingest($document);

        $item = documentCorpusItem($document);

        expect($item->source_updated_at?->getTimestamp())->toBe($document->updated_at->getTimestamp());

        $originalChecksum = $item->checksum;
        $originalChunkIds = $item->chunks()->pluck('id')->all();

        $this->travel(3)->days();

        $document->touch();

        app(IngestDocument::class)->ingest($document->fresh());

        $item->refresh();

        expect($item->checksum)->toBe($originalChecksum)
            ->and($item->chunks()->pluck('id')->all())->toBe($originalChunkIds)
            ->and($item->source_updated_at->getTimestamp())->toBe($document->fresh()->updated_at->getTimestamp());
    });

    it('does nothing while AI search is disabled', function () {
        $document = CorpusDocument::factory()->ready()->create();

        app(IngestDocument::class)->ingest($document);

        expect(CorpusItem::query()->count())->toBe(0);
    });

    it('skips safely when the real embedding driver has no API key', function () {
        enableDocumentAiSearch();
        config()->set('ai.embeddings.driver', 'openrouter');
        config()->set('ai.providers.openrouter.key', '');

        $document = CorpusDocument::factory()->ready()->create();

        app(IngestDocument::class)->ingest($document);

        expect(CorpusItem::query()->count())->toBe(0);
    });
});

describe('document deletion', function () {
    it('removes the stored file, corpus item, and chunks when the document is deleted', function () {
        enableDocumentAiSearch();

        $document = makeCorpusDocument('text-layer.pdf', 'application/pdf', [
            'status' => CorpusDocument::STATUS_READY,
            'extracted_markdown' => 'نص للمستند الذي سيُحذف من الفهرس.',
        ]);

        app(IngestDocument::class)->ingest($document);

        expect(documentCorpusItem($document))->not->toBeNull();

        $document->delete();

        Storage::disk(CorpusDocument::DISK)->assertMissing($document->path);

        expect(documentCorpusItem($document))->toBeNull()
            ->and(CorpusChunk::query()->count())->toBe(0);
    });
});
