<?php

namespace App\Filament\Resources\PrivateTutors\Schemas;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PrivateTutorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات الخصوصي')
                    ->schema([
                        TextInput::make('name')
                            ->label('الاسم')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('url')
                            ->label('الرابط')
                            ->url()
                            ->maxLength(255)
                            ->placeholder('https://example.com'),
                    ])
                    ->columns(2),
            ]);
    }
}
