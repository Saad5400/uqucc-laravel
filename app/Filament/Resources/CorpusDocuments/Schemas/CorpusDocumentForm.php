<?php

namespace App\Filament\Resources\CorpusDocuments\Schemas;

use App\Models\Corpus\CorpusDocument;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CorpusDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label('العنوان')
                    ->helperText('اسم واضح للمستند كما سيظهر في نتائج البحث الذكي (مثال: لائحة الدراسة والاختبارات).')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                FileUpload::make('path')
                    ->label('الملف')
                    ->helperText('PDF أو صورة (PNG / JPG / WebP) بحجم أقصى 20 ميجابايت. تُستخرج النصوص تلقائياً بعد الرفع.')
                    ->disk(CorpusDocument::DISK)
                    ->directory(CorpusDocument::DIRECTORY)
                    ->visibility('private')
                    ->acceptedFileTypes([
                        'application/pdf',
                        'image/png',
                        'image/jpeg',
                        'image/webp',
                    ])
                    ->maxSize(20480)
                    ->storeFileNamesIn('original_filename')
                    ->required()
                    ->visibleOn('create')
                    ->columnSpanFull(),

                Textarea::make('extracted_markdown')
                    ->label('النص المستخرج (ماركداون)')
                    ->helperText('النص الذي استخرجه النظام من الملف. يمكن تصحيحه يدوياً — سيُعاد فهرسة المستند تلقائياً بعد الحفظ.')
                    ->rows(20)
                    ->extraAttributes(['dir' => 'rtl'])
                    ->visibleOn('edit')
                    ->columnSpanFull(),
            ]);
    }
}
