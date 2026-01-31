<?php

namespace App\Filament\Resources\PrivateTutors\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CoursesRelationManager extends RelationManager
{
    protected static string $relationship = 'courses';

    protected static ?string $title = 'المواد';

    protected static ?string $modelLabel = 'مادة';

    protected static ?string $pluralModelLabel = 'مواد';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('اسم المادة')
                    ->searchable()
                    ->sortable(),
            ])
            ->defaultSort('order')
            ->headerActions([
                AttachAction::make()
                    ->label('ربط مادة')
                    ->preloadRecordSelect()
                    ->recordSelect(
                        fn ($select) => $select
                            ->label('المادة')
                            ->searchable()
                    ),
            ])
            ->actions([
                DetachAction::make()
                    ->label('فك الربط'),
            ]);
    }
}
