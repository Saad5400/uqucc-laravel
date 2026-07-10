<?php

namespace App\Filament\Resources\CorpusDocuments\Tables;

use App\Jobs\Ai\ExtractCorpusDocumentJob;
use App\Jobs\Ai\IngestDocumentJob;
use App\Models\Corpus\CorpusDocument;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class CorpusDocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['uploader', 'corpusItem']))
            ->columns([
                TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->sortable()
                    ->description(fn (CorpusDocument $record): string => $record->original_filename),

                TextColumn::make('mime')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'application/pdf' ? 'PDF' : 'صورة')
                    ->color('gray'),

                TextColumn::make('size')
                    ->label('الحجم')
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state))
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->tooltip(fn (CorpusDocument $record): ?string => $record->error)
                    ->sortable(),

                TextColumn::make('corpusItem.status')
                    ->label('الفهرسة')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::indexLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->placeholder('غير مفهرس'),

                TextColumn::make('uploader.name')
                    ->label('رفعه')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('تاريخ الرفع')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        CorpusDocument::STATUS_PENDING => 'بانتظار الاستخراج',
                        CorpusDocument::STATUS_EXTRACTING => 'جارٍ الاستخراج',
                        CorpusDocument::STATUS_READY => 'جاهز',
                        CorpusDocument::STATUS_FAILED => 'فشل',
                    ]),
            ])
            ->recordActions([
                Action::make('preview')
                    ->label('معاينة النص')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->visible(fn (CorpusDocument $record): bool => filled($record->extracted_markdown))
                    ->modalHeading(fn (CorpusDocument $record): string => $record->title)
                    ->schema([
                        Textarea::make('extracted_markdown')
                            ->label('النص المستخرج (ماركداون)')
                            ->rows(24)
                            ->extraAttributes(['dir' => 'rtl'])
                            ->disabled(),
                    ])
                    ->fillForm(fn (CorpusDocument $record): array => [
                        'extracted_markdown' => $record->extracted_markdown,
                    ])
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('إغلاق'),

                Action::make('reextract')
                    ->label('إعادة الاستخراج')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('إعادة استخراج النص')
                    ->modalDescription('سيُعاد استخراج النص من الملف (عبر طبقة النص أو نموذج الرؤية) ثم تُعاد فهرسته. يستبدل هذا أي تعديلات يدوية على النص المستخرج.')
                    ->action(function (CorpusDocument $record): void {
                        ExtractCorpusDocumentJob::dispatch($record->id);

                        Notification::make()
                            ->title('تمت جدولة إعادة الاستخراج')
                            ->success()
                            ->send();
                    }),

                Action::make('reingest')
                    ->label('إعادة الفهرسة')
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->color('info')
                    ->visible(fn (CorpusDocument $record): bool => $record->status === CorpusDocument::STATUS_READY
                        && filled($record->extracted_markdown))
                    ->requiresConfirmation()
                    ->modalHeading('إعادة الفهرسة')
                    ->modalDescription('ستُعاد تجزئة النص المستخرج وتضمينه في فهرس البحث الذكي دون إعادة الاستخراج.')
                    ->action(function (CorpusDocument $record): void {
                        IngestDocumentJob::dispatch($record->id);

                        Notification::make()
                            ->title('تمت جدولة إعادة الفهرسة')
                            ->success()
                            ->send();
                    }),

                EditAction::make()
                    ->label('تعديل'),

                DeleteAction::make()
                    ->label('حذف')
                    ->modalDescription('سيُحذف المستند وملفه المخزن وكل مقاطعه من فهرس البحث الذكي.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('حذف المحدد'),
                ]),
            ])
            ->defaultSort('created_at', 'desc')
            ->poll('15s');
    }

    private static function statusLabel(string $status): string
    {
        return match ($status) {
            CorpusDocument::STATUS_PENDING => 'بانتظار الاستخراج',
            CorpusDocument::STATUS_EXTRACTING => 'جارٍ الاستخراج',
            CorpusDocument::STATUS_READY => 'جاهز',
            CorpusDocument::STATUS_FAILED => 'فشل',
            default => $status,
        };
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            CorpusDocument::STATUS_PENDING => 'gray',
            CorpusDocument::STATUS_EXTRACTING, 'processing' => 'warning',
            CorpusDocument::STATUS_READY => 'success',
            CorpusDocument::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    private static function indexLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'بانتظار الفهرسة',
            'processing' => 'جارٍ الفهرسة',
            'ready' => 'مفهرس',
            'failed' => 'فشلت الفهرسة',
            default => $status,
        };
    }
}
