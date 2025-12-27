<?php

namespace App\Filament\Widgets;

use App\Models\PageViewStat;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class PageViewsStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    protected function getStats(): array
    {
        $totalViews = PageViewStat::sum('view_count');
        $uniqueVisitors = PageViewStat::distinct('ip_address')->whereNotNull('ip_address')->count('ip_address');
        $pagesWithViews = PageViewStat::distinct('page_id')->count('page_id');
        
        // Get average views per page
        $avgViewsPerPage = $pagesWithViews > 0 ? round($totalViews / $pagesWithViews, 1) : 0;

        return [
            Stat::make('إجمالي المشاهدات', number_format($totalViews))
                ->description('عدد مشاهدات الصفحات الكلي')
                ->descriptionIcon('heroicon-m-eye')
                ->color('primary'),

            Stat::make('الزوار الفريدون', number_format($uniqueVisitors))
                ->description('عدد عناوين IP المختلفة')
                ->descriptionIcon('heroicon-m-user')
                ->color('success'),

            Stat::make('الصفحات المشاهدة', $pagesWithViews)
                ->description('عدد الصفحات التي تم مشاهدتها')
                ->descriptionIcon('heroicon-m-document-text')
                ->color('info'),

            Stat::make('متوسط المشاهدات', number_format($avgViewsPerPage, 1))
                ->description('متوسط المشاهدات لكل صفحة')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('warning'),
        ];
    }
}
