<?php

namespace App\Filament\Widgets;

use App\Models\PageViewStat;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class PageViewsChart extends ChartWidget
{
    protected ?string $heading = 'مشاهدات الصفحات خلال آخر 30 يوم';

    protected static ?int $sort = 7;

    protected function getData(): array
    {
        $data = [];
        $labels = [];

        // Get last 30 days
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $labels[] = $date->locale('ar')->format('M d');

            $count = PageViewStat::whereDate('last_viewed_at', $date->toDateString())
                ->sum('view_count');

            $data[] = $count;
        }

        return [
            'datasets' => [
                [
                    'label' => 'عدد المشاهدات',
                    'data' => $data,
                    'backgroundColor' => 'rgba(34, 197, 94, 0.1)',
                    'borderColor' => 'rgb(34, 197, 94)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }
}
