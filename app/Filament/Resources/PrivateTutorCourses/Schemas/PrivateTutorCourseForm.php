<?php

namespace App\Filament\Resources\PrivateTutorCourses\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PrivateTutorCourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('اسم المادة')
                    ->required()
                    ->maxLength(255),
            ]);
    }
}
