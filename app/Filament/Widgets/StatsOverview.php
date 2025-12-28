<?php

namespace App\Filament\Widgets;

use App\Models\Page;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totalPages = Page::count();
        $rootPages = Page::whereNull('parent_id')->count();
        $childPages = Page::whereNotNull('parent_id')->count();
        $totalContributors = DB::table('page_user')->count(DB::raw('DISTINCT user_id'));

        // Get pages count from last 7 days for trend
        $pagesLastWeek = Page::where('created_at', '>=', now()->subWeek())->count();
        $pagesThisWeek = Page::where('created_at', '>=', now()->startOfWeek())->count();

        return [
            Stat::make('إجمالي الصفحات', $totalPages)
                ->description('عدد كل الصفحات في النظام')
                ->descriptionIcon('heroicon-m-document-text')
                ->chart([7, 12, 18, 24, 30, 42, $totalPages])
                ->color('primary'),

            Stat::make('الصفحات الرئيسية', $rootPages)
                ->description('الصفحات التي ليس لها صفحة أب')
                ->descriptionIcon('heroicon-m-folder')
                ->color('success'),

            Stat::make('الصفحات الفرعية', $childPages)
                ->description('الصفحات التي لها صفحة أب')
                ->descriptionIcon('heroicon-m-document-duplicate')
                ->color('warning'),

            Stat::make('المساهمون', $totalContributors)
                ->description('عدد المستخدمين المساهمين في المحتوى')
                ->descriptionIcon('heroicon-m-users')
                ->color('info'),
        ];
    }
}
