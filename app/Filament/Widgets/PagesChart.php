<?php

namespace App\Filament\Widgets;

use App\Models\Page;
use Filament\Widgets\ChartWidget;

class PagesChart extends ChartWidget
{
    protected ?string $heading = 'إنشاء الصفحات خلال آخر 12 شهر';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $data = [];
        $labels = [];

        // Get last 12 months
        for ($i = 11; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $labels[] = $date->locale('ar')->format('M Y');

            $count = Page::whereYear('created_at', $date->year)
                ->whereMonth('created_at', $date->month)
                ->count();

            $data[] = $count;
        }

        return [
            'datasets' => [
                [
                    'label' => 'عدد الصفحات المنشأة',
                    'data' => $data,
                    'backgroundColor' => 'rgba(251, 191, 36, 0.1)',
                    'borderColor' => 'rgb(251, 191, 36)',
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
