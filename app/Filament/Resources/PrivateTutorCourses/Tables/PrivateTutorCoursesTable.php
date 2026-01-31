<?php

namespace App\Filament\Resources\PrivateTutorCourses\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PrivateTutorCoursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                return $query
                    ->withCount('tutors')
                    ->orderBy('order', 'ASC');
            })
            ->columns([
                TextColumn::make('name')
                    ->label('اسم المادة')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('tutors_count')
                    ->label('الخصوصيين')
                    ->counts('tutors')
                    ->badge()
                    ->color('success'),

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
            ->recordActions([
                EditAction::make()
                    ->label('تعديل'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('حذف المحدد'),
                ]),
            ])
            ->reorderable('order')
            ->defaultSort('order');
    }
}
