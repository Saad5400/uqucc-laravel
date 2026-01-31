<?php

namespace App\Filament\Resources\PrivateTutors\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PrivateTutorForm
{
    public static function configure(Schema $schema): Schema
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
                    ->maxLength(255)
                    ->placeholder('https://example.com'),
            ]);
    }
}
