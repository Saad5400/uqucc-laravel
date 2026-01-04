<?php

namespace App\Filament\Widgets;

use App\Models\BotCommandStat;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class BotCommandsStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        $totalCommands = BotCommandStat::sum('count');
        $uniqueUsers = BotCommandStat::whereNotNull('telegram_user_id')
            ->distinct()
            ->count('telegram_user_id');
        $uniqueCommands = BotCommandStat::distinct()
            ->count('command_name');

        // Get most used command
        $mostUsedCommand = BotCommandStat::select('command_name', DB::raw('SUM(count) as total'))
            ->groupBy('command_name')
            ->orderByDesc('total')
            ->first();

        return [
            Stat::make('إجمالي استخدام الأوامر', number_format($totalCommands))
                ->description('عدد المرات التي تم استخدام أوامر البوت')
                ->descriptionIcon('heroicon-m-command-line')
                ->color('primary'),

            Stat::make('المستخدمون النشطون', $uniqueUsers)
                ->description('عدد المستخدمين الذين استخدموا البوت')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('success'),

            Stat::make('الأوامر المستخدمة', $uniqueCommands)
                ->description('عدد الأوامر المختلفة المستخدمة')
                ->descriptionIcon('heroicon-m-squares-plus')
                ->color('info'),

            Stat::make('الأمر الأكثر استخداماً', $mostUsedCommand?->command_name ?? '—')
                ->description($mostUsedCommand ? number_format($mostUsedCommand->total).' مرة' : 'لا توجد بيانات')
                ->descriptionIcon('heroicon-m-fire')
                ->color('warning'),
        ];
    }
}
