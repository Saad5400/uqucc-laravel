<?php

namespace App\Filament\Resources\CorpusDocuments\Pages;

use App\Filament\Resources\CorpusDocuments\CorpusDocumentResource;
use App\Jobs\Ai\ExtractCorpusDocumentJob;
use App\Models\Corpus\CorpusDocument;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;

class CreateCorpusDocument extends CreateRecord
{
    protected static string $resource = CorpusDocumentResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['disk'] = CorpusDocument::DISK;
        $data['status'] = CorpusDocument::STATUS_PENDING;
        $data['uploaded_by'] = auth()->id();

        return $data;
    }

    /**
     * The mime and size come from the stored bytes (not client-supplied
     * values), then extraction is queued — the admin sees the row move
     * through الحالة on the list page.
     */
    protected function afterCreate(): void
    {
        /** @var CorpusDocument $document */
        $document = $this->record;

        $disk = Storage::disk($document->disk);

        $document->forceFill([
            'mime' => $disk->mimeType($document->path) ?: null,
            'size' => $disk->size($document->path),
        ])->save();

        ExtractCorpusDocumentJob::dispatch($document->id);
    }
}
