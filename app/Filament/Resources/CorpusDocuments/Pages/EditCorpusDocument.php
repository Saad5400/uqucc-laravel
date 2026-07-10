<?php

namespace App\Filament\Resources\CorpusDocuments\Pages;

use App\Filament\Resources\CorpusDocuments\CorpusDocumentResource;
use App\Jobs\Ai\IngestDocumentJob;
use App\Models\Corpus\CorpusDocument;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCorpusDocument extends EditRecord
{
    protected static string $resource = CorpusDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('حذف')
                ->modalDescription('سيُحذف المستند وملفه المخزن وكل مقاطعه من فهرس البحث الذكي.'),
        ];
    }

    /**
     * A manual correction of the extracted markdown re-indexes the document
     * automatically, so the corpus never serves stale chunks of a text the
     * admin just fixed.
     */
    protected function afterSave(): void
    {
        /** @var CorpusDocument $document */
        $document = $this->record;

        if ($document->wasChanged(['extracted_markdown', 'title'])) {
            IngestDocumentJob::dispatch($document->id);
        }
    }
}
