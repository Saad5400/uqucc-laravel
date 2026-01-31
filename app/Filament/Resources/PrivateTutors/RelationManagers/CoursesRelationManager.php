<?php

namespace App\Filament\Resources\PrivateTutors\RelationManagers;

use App\Models\PrivateTutor\PrivateTutorCourse;
use Filament\Actions\AttachAction;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\TextInput;
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

    protected static ?string $inverseRelationship = 'tutors';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('اسم المادة')
                    ->required()
                    ->maxLength(255),
            ]);
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
                CreateAction::make()
                    ->label('إضافة مادة جديدة'),
                AttachAction::make()
                    ->label('ربط مادة موجودة')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name'])
                    ->recordTitle(fn (PrivateTutorCourse $record) => $record->name),
            ])
            ->actions([
                DetachAction::make()
                    ->label('فك الربط'),
            ]);
    }
}
