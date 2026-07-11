<?php

use App\Models\Corpus\CorpusDocument;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(CorpusDocument::DISK);
});

function storedDocument(array $overrides = []): CorpusDocument
{
    $path = CorpusDocument::DIRECTORY.'/regulations.pdf';

    Storage::disk(CorpusDocument::DISK)->put($path, '%PDF-1.4 fake');

    return CorpusDocument::factory()->create([
        'original_filename' => 'regulations.pdf',
        'path' => $path,
        'mime' => 'application/pdf',
        'status' => CorpusDocument::STATUS_READY,
        ...$overrides,
    ]);
}

it('serves the original file of a ready document publicly', function () {
    $document = storedDocument();

    $response = $this->get(route('documents.show', $document));

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('answers 404 for documents that are not ready', function (string $status) {
    $document = storedDocument(['status' => $status]);

    $this->get(route('documents.show', $document))->assertNotFound();
})->with([
    'pending' => CorpusDocument::STATUS_PENDING,
    'extracting' => CorpusDocument::STATUS_EXTRACTING,
    'failed' => CorpusDocument::STATUS_FAILED,
]);

it('answers 404 when the stored file is gone', function () {
    $document = storedDocument();

    Storage::disk(CorpusDocument::DISK)->delete($document->path);

    $this->get(route('documents.show', $document))->assertNotFound();
});

it('answers 404 for unknown ids', function () {
    $this->get('/mstnd/999')->assertNotFound();
});
