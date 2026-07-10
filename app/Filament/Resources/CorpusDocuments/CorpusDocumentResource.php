<?php

namespace App\Filament\Resources\CorpusDocuments;

use App\Filament\Resources\CorpusDocuments\Pages\CreateCorpusDocument;
use App\Filament\Resources\CorpusDocuments\Pages\EditCorpusDocument;
use App\Filament\Resources\CorpusDocuments\Pages\ListCorpusDocuments;
use App\Filament\Resources\CorpusDocuments\Schemas\CorpusDocumentForm;
use App\Filament\Resources\CorpusDocuments\Tables\CorpusDocumentsTable;
use App\Models\Corpus\CorpusDocument;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CorpusDocumentResource extends Resource
{
    protected static ?string $model = CorpusDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowUp;

    protected static ?string $modelLabel = 'مستند';

    protected static ?string $pluralModelLabel = 'مستندات الذكاء الاصطناعي';

    protected static ?string $navigationLabel = 'مستندات الذكاء الاصطناعي';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return CorpusDocumentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CorpusDocumentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCorpusDocuments::route('/'),
            'create' => CreateCorpusDocument::route('/create'),
            'edit' => EditCorpusDocument::route('/{record}/edit'),
        ];
    }
}
