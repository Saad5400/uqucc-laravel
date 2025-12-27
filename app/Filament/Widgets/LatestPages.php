<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Pages\PageResource;
use App\Models\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestPages extends TableWidget
{
    protected static ?string $heading = 'آخر الصفحات المحدثة';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Page::query()
                    ->latest('updated_at')
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->url(fn (Page $record): string => PageResource::getUrl('edit', ['record' => $record]))
                    ->limit(50),

                TextColumn::make('parent.title')
                    ->label('الصفحة الأب')
                    ->default('—')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('users_count')
                    ->label('عدد المساهمين')
                    ->counts('users')
                    ->badge()
                    ->color('success'),

                TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->since()
                    ->description(fn (Page $record): string => $record->updated_at->locale('ar')->diffForHumans()),
            ])
            ->paginated(false);
    }
}
