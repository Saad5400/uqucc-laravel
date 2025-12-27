<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Pages\PageResource;
use App\Models\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class MostViewedPagesWidget extends BaseWidget
{
    protected static ?string $heading = 'الصفحات الأكثر مشاهدة';

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Page::query()
                    ->select('pages.*')
                    ->join('page_view_stats', 'pages.id', '=', 'page_view_stats.page_id')
                    ->selectRaw('SUM(page_view_stats.view_count) as total_views')
                    ->groupBy('pages.id')
                    ->orderByDesc('total_views')
                    ->limit(10)
            )
            ->defaultKeySort(false)
            ->columns([
                TextColumn::make('title')
                    ->label('العنوان')
                    ->searchable()
                    ->weight('bold')
                    ->url(fn (Page $record): string => PageResource::getUrl('edit', ['record' => $record]))
                    ->limit(50),

                TextColumn::make('slug')
                    ->label('المسار')
                    ->copyable()
                    ->copyMessage('تم نسخ المسار')
                    ->limit(40),

                TextColumn::make('total_views')
                    ->label('عدد المشاهدات')
                    ->sortable()
                    ->badge()
                    ->color('success')
                    ->formatStateUsing(fn ($state) => number_format($state)),

                TextColumn::make('parent.title')
                    ->label('الصفحة الأب')
                    ->default('—')
                    ->badge()
                    ->color('gray'),
            ])
            ->paginated(false);
    }
}
