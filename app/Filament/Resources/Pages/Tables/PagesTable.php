<?php

namespace App\Filament\Resources\Pages\Tables;

use App\Filament\Resources\Pages\Pages\ListPages;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                // sort by first showing pages with no parent, then by its website hidden status, then by order
                return $query
                    ->withCount('children')
                    ->withTrashed()
                    ->orderByRaw('CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END ASC')
                    ->orderBy('hidden', 'ASC')
                    ->orderBy('order', 'ASC');
            })
            ->columns([
                TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record) => $record->slug),

                TextColumn::make('parent.title')
                    ->label('الصفحة الأب')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('children_count')
                    ->label('الصفحات الفرعية')
                    ->counts('children')
                    ->badge()
                    ->color('success'),

                IconColumn::make('hidden')
                    ->label('مخفية (الموقع)')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('hidden_from_bot')
                    ->label('مخفية (البوت)')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('smart_search')
                    ->label('بحث ذكي')
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),


                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Root pages filter (default on list page)
                Filter::make('root_only')
                    ->label('الصفحات الرئيسية فقط')
                    ->query(fn (Builder $query): Builder => $query->whereNull('parent_id'))
                    ->default(fn (Component $livewire) => $livewire instanceof ListPages),

                // Visibility filters
                TernaryFilter::make('hidden')
                    ->label('مخفية من الموقع')
                    ->placeholder('الكل')
                    ->trueLabel('مخفية')
                    ->falseLabel('ظاهرة'),

                TernaryFilter::make('hidden_from_bot')
                    ->label('مخفية من البوت')
                    ->placeholder('الكل')
                    ->trueLabel('مخفية')
                    ->falseLabel('ظاهرة'),

                // Search & Response filters
                TernaryFilter::make('smart_search')
                    ->label('البحث الذكي')
                    ->placeholder('الكل')
                    ->trueLabel('مفعّل')
                    ->falseLabel('معطّل'),

                TernaryFilter::make('requires_prefix')
                    ->label('يتطلب بادئة')
                    ->placeholder('الكل')
                    ->trueLabel('نعم')
                    ->falseLabel('لا'),

                // Quick Response filters
                TernaryFilter::make('quick_response_auto_extract_message')
                    ->label('استخراج رسالة الرد تلقائياً')
                    ->placeholder('الكل')
                    ->trueLabel('مفعّل')
                    ->falseLabel('معطّل'),

                TernaryFilter::make('quick_response_auto_extract_buttons')
                    ->label('استخراج أزرار الرد تلقائياً')
                    ->placeholder('الكل')
                    ->trueLabel('مفعّل')
                    ->falseLabel('معطّل'),

                TernaryFilter::make('quick_response_auto_extract_attachments')
                    ->label('استخراج مرفقات الرد تلقائياً')
                    ->placeholder('الكل')
                    ->trueLabel('مفعّل')
                    ->falseLabel('معطّل'),

                TernaryFilter::make('quick_response_send_link')
                    ->label('إرسال رابط في الرد السريع')
                    ->placeholder('الكل')
                    ->trueLabel('نعم')
                    ->falseLabel('لا'),

                TernaryFilter::make('quick_response_send_screenshot')
                    ->label('إرسال لقطة شاشة في الرد السريع')
                    ->placeholder('الكل')
                    ->trueLabel('نعم')
                    ->falseLabel('لا'),

                // Level filter
                SelectFilter::make('level')
                    ->label('المستوى')
                    ->options([
                        0 => 'المستوى 0 (الجذر)',
                        1 => 'المستوى 1',
                        2 => 'المستوى 2',
                        3 => 'المستوى 3',
                        4 => 'المستوى 4',
                        5 => 'المستوى 5',
                    ])
                    ->placeholder('كل المستويات'),

                // Extension filter
                SelectFilter::make('extension')
                    ->label('الامتداد')
                    ->options(function () {
                        return \App\Models\Page::query()
                            ->whereNotNull('extension')
                            ->distinct()
                            ->pluck('extension', 'extension')
                            ->toArray();
                    })
                    ->placeholder('كل الامتدادات'),

                // Parent page filter
                SelectFilter::make('parent_id')
                    ->label('الصفحة الأب')
                    ->relationship('parent', 'title')
                    ->searchable()
                    ->preload()
                    ->placeholder('كل الصفحات'),

                // Has children filter
                Filter::make('has_children')
                    ->label('لديها صفحات فرعية')
                    ->query(fn (Builder $query): Builder => $query->has('children'))
                    ->toggle(),

                // Has users/contributors filter
                Filter::make('has_users')
                    ->label('لديها مساهمون')
                    ->query(fn (Builder $query): Builder => $query->has('users'))
                    ->toggle(),

                // No content filter
                Filter::make('no_content')
                    ->label('بدون محتوى')
                    ->query(fn (Builder $query): Builder => $query->whereNull('html_content')->orWhere('html_content', ''))
                    ->toggle(),

                // Soft deletes filter
                TrashedFilter::make()
                    ->label('المحذوفة'),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('تعديل'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('حذف المحدد'),
                    ForceDeleteBulkAction::make()
                        ->label('حذف نهائي'),
                    RestoreBulkAction::make()
                        ->label('استرجاع'),
                ]),
            ])
            ->reorderable('order')
            ->defaultSort('order')
            ->poll('30s');
    }
}
