<?php

namespace App\Filament\Resources\Authors\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AuthorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('معلومات المؤلف')
                    ->description('المعلومات الأساسية للمؤلف')
                    ->schema([
                        TextInput::make('username')
                            ->label('اسم المستخدم')
                            ->helperText('معرف فريد للمؤلف (مثل: saad)')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255)
                            ->alphaDash()
                            ->columnSpanFull(),

                        TextInput::make('name')
                            ->label('الاسم')
                            ->helperText('الاسم الكامل للمؤلف')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('url')
                            ->label('الرابط')
                            ->helperText('رابط الموقع أو الملف الشخصي للمؤلف')
                            ->url()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TextInput::make('avatar')
                            ->label('الصورة الرمزية')
                            ->helperText('رابط صورة المؤلف')
                            ->url()
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ]);
    }
}
