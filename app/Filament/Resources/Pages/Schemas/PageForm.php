<?php

namespace App\Filament\Resources\Pages\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('معلومات أساسية')
                    ->description('المعلومات الأساسية للصفحة')
                    ->schema([
                        TextInput::make('title')
                            ->label('العنوان')
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Set $set, string $operation) {
                                if ($operation == 'create') {
                                    $set('slug', '/' . str($state)->slug());
                                }
                            }),

                        TextInput::make('slug')
                            ->extraAttributes([
                                'dir' => 'ltr',
                            ])
                            ->label('الرابط')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->prefix(url('/')),

                        Textarea::make('description')
                            ->label('الوصف')
                            ->rows(3)
                            ->columnSpanFull(),

                        Select::make('parent_id')
                            ->label('الصفحة الأب')
                            ->default(request('default_parent_id') ?? null)
                            ->relationship('parent', 'title')
                            ->searchable()
                            ->preload(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('المحتوى')
                    ->columns(1)
                    ->columnSpanFull()
                    ->description('محتوى الصفحة بصيغة HTML')
                    ->schema([
                        RichEditor::make('html_content')
                            ->label('المحتوى الرئيسي')
                            ->required()
                            ->columnSpanFull(),

                        Textarea::make('stem')
                            ->label('محتوى إضافي (نص عادي)')
                            ->helperText('يمكن استخدام هذا الحقل لمحتوى إضافي أو ملاحظات')
                            ->rows(5)
                            ->columnSpanFull(),
                    ]),

                Section::make('إعدادات الصفحة')
                    ->columnSpanFull()
                    ->description('الإعدادات والخيارات الإضافية')
                    ->schema([
                        TextInput::make('icon')
                            ->label('الأيقونة')
                            ->helperText('اسم الأيقونة من Heroicons'),

                        FileUpload::make('og_image')
                            ->label('صورة المعاينة')
                            ->image()
                            ->directory('og-images')
                            ->visibility('public'),

                        TextInput::make('order')
                            ->label('الترتيب')
                            ->numeric()
                            ->default(0)
                            ->helperText('يُستخدم لترتيب الصفحات في القائمة'),

                        Toggle::make('hidden')
                            ->label('مخفية')
                            ->helperText('إخفاء الصفحة من القوائم العامة')
                            ->default(false),
                    ])
                    ->columns(2),
            ]);
    }
}
