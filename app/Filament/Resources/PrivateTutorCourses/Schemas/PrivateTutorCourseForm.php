<?php

namespace App\Filament\Resources\PrivateTutorCourses\Schemas;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PrivateTutorCourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات المادة')
                    ->schema([
                        TextInput::make('name')
                            ->label('اسم المادة')
                            ->required()
                            ->maxLength(255),
                    ]),
            ]);
    }
}
