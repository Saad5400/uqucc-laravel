<?php

namespace App\Filament\Resources\Pages\Tables;

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\ListPages;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\Operation;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                    ->label('مخفية')
                    ->boolean()
                    ->sortable(),

                TextColumn::make('order')
                    ->label('الترتيب')
                    ->numeric()
                    ->sortable(),

                ImageColumn::make('og_image')
                    ->label('الصورة')
                    ->toggleable(),

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
                Filter::make('root_only')
                    ->label('الصفحات الرئيسية فقط')
                    ->query(fn (Builder $query): Builder => $query->whereNull('parent_id'))
                    ->default(fn(Component $livewire) => $livewire instanceof ListPages),

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
