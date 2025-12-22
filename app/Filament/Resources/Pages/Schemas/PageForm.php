<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Filament\Forms\Blocks\AlertBlock;
use App\Filament\Forms\Blocks\CollapsibleBlock;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
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
                            ->json()
                            ->customBlocks([
                                AlertBlock::class,
                                CollapsibleBlock::class,
                            ])
                            ->activePanel('customBlocks')
                            ->columnSpanFull(),
                    ]),

                Section::make('إعدادات الصفحة')
                    ->columnSpanFull()
                    ->description('الإعدادات والخيارات الإضافية')
                    ->schema([
                        TextInput::make('icon')
                            ->label('الأيقونة')
                            ->helperText('اسم الأيقونة من Heroicons'),

                        Toggle::make('hidden')
                            ->label('مخفية')
                            ->helperText('إخفاء الصفحة من القوائم العامة')
                            ->default(false),
                    ])
                    ->columns(2),

                Section::make('ردود Discord السريعة')
                    ->columnSpanFull()
                    ->description('تهيئة محتوى الرد الذي يمكن للبوت إرساله مباشرة للمستخدمين')
                    ->schema([
                        Toggle::make('quick_response_enabled')
                            ->label('تفعيل الرد السريع')
                            ->helperText('عند التفعيل يمكن للبوت استخدام هذه الصفحة كقالب رد جاهز')
                            ->default(false),

                        Grid::make()
                            ->schema([
                                Toggle::make('quick_response_send_link')
                                    ->label('إرسال رابط الصفحة مع الرد')
                                    ->default(true),
                            ])
                            ->columns(3)
                            ->hidden(fn (Get $get) => ! $get('quick_response_enabled')),

                        Repeater::make('quick_response_buttons')
                            ->label('الأزرار')
                            ->schema([
                                TextInput::make('text')
                                    ->label('عنوان الزر')
                                    ->required()
                                    ->maxLength(50),

                                TextInput::make('url')
                                    ->label('رابط الزر')
                                    ->url()
                                    ->required()
                                    ->maxLength(2048),
                            ])
                            ->hidden(fn (Get $get) => ! $get('quick_response_enabled'))
                            ->addActionLabel('إضافة زر')
                            ->columns(2)
                            ->reorderable()
                            ->collapsed(),

                        Textarea::make('quick_response_message')
                            ->label('نص الرد')
                            ->helperText('نص قصير يمكن للبوت إرساله مع الرابط في الديسكورد')
                            ->rows(4)
                            ->columnSpanFull()
                            ->hidden(fn (Get $get) => ! $get('quick_response_enabled')),

                        FileUpload::make('quick_response_attachments')
                            ->label('مرفقات الرد (صور/ملفات)')
                            ->directory('quick-responses')
                            ->visibility('public')
                            ->multiple()
                            ->downloadable()
                            ->openable()
                            ->preserveFilenames()
                            ->helperText('تُرفع مع الرد في حال احتجنا لإضافة صور أو ملفات داعمة')
                            ->columnSpanFull()
                            ->hidden(fn (Get $get) => ! $get('quick_response_enabled')),
                    ]),
            ]);
    }
}
