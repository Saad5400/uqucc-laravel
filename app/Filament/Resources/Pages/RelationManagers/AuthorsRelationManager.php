<?php

namespace App\Filament\Resources\Pages\RelationManagers;

use App\Filament\Resources\Authors\AuthorResource;
use App\Filament\Resources\Authors\Schemas\AuthorForm;
use App\Filament\Resources\Authors\Tables\AuthorsTable;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class AuthorsRelationManager extends RelationManager
{
    protected static ?string $relatedResource = AuthorResource::class;

    protected static string $relationship = 'authors';

    protected static ?string $title = 'المؤلفون';

    protected static ?string $modelLabel = 'مؤلف';

    protected static ?string $pluralModelLabel = 'مؤلفون';

    public function form(Schema $schema): Schema
    {
        return AuthorForm::configure($schema);
    }

    public function table(Table $table): Table
    {
        return AuthorsTable::configure($table)
            ->headerActions([
                CreateAction::make()
                    ->label('إنشاء مؤلف'),
                AttachAction::make()
                    ->label('ربط مؤلف')
                    ->preloadRecordSelect()
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('order')
                            ->label('الترتيب')
                            ->numeric()
                            ->default(fn () => $this->getOwnerRecord()->authors()->max('order') + 1 ?? 1)
                            ->required(),
                    ]),
            ]);
    }
}
