<?php

namespace App\Filament\Resources\PrivateTutorCourses\RelationManagers;

use App\Models\PrivateTutor\PrivateTutor;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\TextInput;
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

    protected static ?string $inverseRelationship = 'courses';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('الاسم')
                    ->required()
                    ->maxLength(255),
                TextInput::make('url')
                    ->label('الرابط')
                    ->url()
                    ->maxLength(255),
            ]);
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
                CreateAction::make()
                    ->label('إضافة خصوصي جديد'),
                AttachAction::make()
                    ->label('ربط خصوصي موجود')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name'])
                    ->recordTitle(fn (PrivateTutor $record) => $record->name),
            ])
            ->actions([
                DetachAction::make()
                    ->label('فك الربط'),
            ]);
    }
}
