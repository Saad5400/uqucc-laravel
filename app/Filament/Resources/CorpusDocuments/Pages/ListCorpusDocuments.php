<?php

namespace App\Filament\Resources\CorpusDocuments\Pages;

use App\Filament\Resources\CorpusDocuments\CorpusDocumentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCorpusDocuments extends ListRecords
{
    protected static string $resource = CorpusDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('رفع مستند'),
        ];
    }
}
