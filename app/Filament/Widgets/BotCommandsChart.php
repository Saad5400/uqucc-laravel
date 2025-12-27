<?php

namespace App\Filament\Widgets;

use App\Models\BotCommandStat;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

class BotCommandsChart extends ChartWidget
{
    protected ?string $heading = 'استخدام أوامر البوت خلال آخر 30 يوم';

    protected static ?int $sort = 6;

    protected function getData(): array
    {
        $data = [];
        $labels = [];

        // Get last 30 days
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $labels[] = $date->locale('ar')->format('M d');

            $count = BotCommandStat::whereDate('last_used_at', $date->toDateString())
                ->sum('count');

            $data[] = $count;
        }

        return [
            'datasets' => [
                [
                    'label' => 'عدد الأوامر المستخدمة',
                    'data' => $data,
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'borderColor' => 'rgb(59, 130, 246)',
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
