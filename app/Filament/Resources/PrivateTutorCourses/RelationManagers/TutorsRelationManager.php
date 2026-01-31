<?php

namespace App\Filament\Resources\PrivateTutorCourses\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TutorsRelationManager extends RelationManager
{
    protected static string $relationship = 'tutors';

    protected static ?string $title = 'الخصوصيين';

    protected static ?string $modelLabel = 'خصوصي';

    protected static ?string $pluralModelLabel = 'خصوصيين';

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
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('url')
                    ->label('الرابط')
                    ->url(fn ($record) => $record->url)
                    ->openUrlInNewTab()
                    ->limit(30),
            ])
            ->defaultSort('order')
            ->headerActions([
                AttachAction::make()
                    ->label('ربط خصوصي')
                    ->preloadRecordSelect()
                    ->recordSelect(
                        fn ($select) => $select
                            ->label('الخصوصي')
                            ->searchable()
                    ),
            ])
            ->actions([
                DetachAction::make()
                    ->label('فك الربط'),
            ]);
    }
}
