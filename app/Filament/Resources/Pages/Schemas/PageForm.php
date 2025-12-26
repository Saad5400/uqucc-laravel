<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Filament\Forms\Blocks\AlertBlock;
use App\Filament\Forms\Blocks\CollapsibleBlock;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

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
                                    $set('slug', '/'.str($state)->slug());
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
                            ->fileAttachmentsDisk('public')
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
                            ->label('إخفاء من الموقع')
                            ->helperText('إخفاء الصفحة من الموقع الإلكتروني')
                            ->reactive()
                            ->default(false),

                        Toggle::make('hidden_from_bot')
                            ->label('إخفاء من البوت')
                            ->helperText('إخفاء الصفحة من بوت التيليجرام')
                            ->default(false),

                        Toggle::make('smart_search')
                            ->label('البحث الذكي')
                            ->helperText('عند التفعيل، يمكن العثور على الصفحة بالبحث في أي جزء من العنوان')
                            ->default(false),

                        Toggle::make('requires_prefix')
                            ->label('يتطلب كلمة "دليل"')
                            ->helperText('عند التفعيل، يجب على المستخدم كتابة "دليل" قبل اسم الصفحة للبحث عنها')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('ردود تيليجرام السريعة')
                    ->columnSpanFull()
                    ->description('الرد السريع مفعّل دائماً. يمكنك تخصيص المحتوى أو تفعيل الاستخراج التلقائي من المحتوى الرئيسي')
                    ->schema([
                        Toggle::make('quick_response_auto_extract')
                            ->label('الاستخراج التلقائي من المحتوى')
                            ->reactive()
                            ->helperText('عند التفعيل، سيتم استخراج نص الرد والأزرار والمرفقات تلقائياً من المحتوى الرئيسي')
                            ->default(false),

                        Toggle::make('quick_response_send_link')
                            ->label('إرسال رابط الصفحة مع الرد')
                            ->default(true),

                        Toggle::make('quick_response_send_screenshot')
                            ->label('إرسال لقطة شاشة للصفحة')
                            ->reactive()
                            ->helperText('عند التفعيل، سيتم إرسال لقطة شاشة من الصفحة مع المحتوى المخصص')
                            ->visible(fn (Get $get) => ! $get('hidden'))
                            ->default(false),

                        Toggle::make('quick_response_customize_message')
                            ->label('تخصيص نص الرد')
                            ->reactive()
                            ->helperText('تجاوز النص المستخرج تلقائياً واستخدام نص مخصص')
                            ->hidden(fn (Get $get) => ! $get('quick_response_auto_extract'))
                            ->default(false),

                        RichEditor::make('quick_response_message')
                            ->label('نص الرد')
                            ->helperText('نص قصير يمكن للبوت إرساله مع الرابط في التيليجرام. التنسيقات المدعومة: عريض، مائل، تسطير، شطب، كود، روابط')
                            ->toolbarButtons([
                                ['bold', 'italic', 'underline', 'strike', 'link'],
                                ['codeBlock'],
                                ['undo', 'redo'],
                            ])
                            ->columnSpanFull()
                            ->visible(fn (Get $get) => ! $get('quick_response_auto_extract')
                                || ($get('quick_response_auto_extract') && $get('quick_response_customize_message'))
                            ),

                        Toggle::make('quick_response_customize_buttons')
                            ->label('تخصيص الأزرار')
                            ->reactive()
                            ->helperText('تجاوز الأزرار المستخرجة تلقائياً واستخدام أزرار مخصصة')
                            ->hidden(fn (Get $get) => ! $get('quick_response_auto_extract'))
                            ->default(false),

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

                                Select::make('size')
                                    ->label('حجم الزر')
                                    ->options([
                                        'full' => 'عرض كامل (زر واحد في السطر)',
                                        'half' => 'نصف عرض (زران في السطر)',
                                        'third' => 'ثلث عرض (ثلاثة أزرار في السطر)',
                                    ])
                                    ->default('full')
                                    ->required()
                                    ->helperText('عدد الأزرار في السطر الواحد'),
                            ])
                            ->visible(fn (Get $get) => ! $get('quick_response_auto_extract')
                                || ($get('quick_response_auto_extract') && $get('quick_response_customize_buttons'))
                            )
                            ->addActionLabel('إضافة زر')
                            ->columns(3)
                            ->reorderable()
                            ->collapsed()
                            ->helperText(fn (Get $get) => ! empty($get('quick_response_attachments'))
                                    ? '⚠️ ملاحظة: يمكن إرسال الأزرار مع الصور (حتى مع عدة صور)، لكن قد لا تعمل مع المستندات. إذا رفض تيليجرام الجمع بينهما فسيتم إرسال المحتوى بدون أحدهما.'
                                    : null
                            ),

                        Toggle::make('quick_response_customize_attachments')
                            ->label('تخصيص المرفقات')
                            ->reactive()
                            ->helperText('تجاوز المرفقات المستخرجة تلقائياً واستخدام مرفقات مخصصة')
                            ->hidden(fn (Get $get) => ! $get('quick_response_auto_extract'))
                            ->default(false),

                        FileUpload::make('quick_response_attachments')
                            ->disk('public')
                            ->label('مرفقات الرد (صور/ملفات)')
                            ->directory('quick-responses')
                            ->visibility('public')
                            ->multiple()
                            ->downloadable()
                            ->openable()
                            ->preserveFilenames()
                            ->helperText(fn (Get $get) => ! empty($get('quick_response_buttons'))
                                    ? '⚠️ ملاحظة: يمكن إرسال الأزرار مع الصور (حتى مع عدة صور)، لكن قد لا تعمل مع المستندات. إذا رفض تيليجرام الجمع بينهما فسيتم إرسال المحتوى بدون أحدهما.'
                                    : 'تُرفع مع الرد في حال احتجنا لإضافة صور أو ملفات داعمة'
                            )
                            ->columnSpanFull()
                            ->visible(fn (Get $get) => ! $get('quick_response_auto_extract')
                                || ($get('quick_response_auto_extract') && $get('quick_response_customize_attachments'))
                            ),
                    ]),
            ]);
    }
}
