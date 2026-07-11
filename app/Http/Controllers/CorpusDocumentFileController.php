<?php

namespace App\Http\Controllers;

use App\Models\Corpus\CorpusDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the original file of a READY corpus document (official regulations
 * and rules PDFs) publicly — the citable source URL for AI answers drawn
 * from uploaded documents. Pending/extracting/failed documents and missing
 * files answer 404.
 */
class CorpusDocumentFileController extends Controller
{
    public function __invoke(CorpusDocument $document): StreamedResponse
    {
        abort_unless($document->status === CorpusDocument::STATUS_READY, 404);

        $disk = Storage::disk($document->disk);

        abort_unless($disk->exists($document->path), 404);

        return $disk->response($document->path, $document->original_filename, [
            'Content-Type' => $document->mime ?? 'application/octet-stream',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
