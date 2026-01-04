<?php

namespace App\Filament\Widgets;

use App\Models\BotCommandStat;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class MostUsedCommandsWidget extends BaseWidget
{
    protected static ?string $heading = 'الأوامر الأكثر استخداماً';

    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                BotCommandStat::query()
                    ->select('command_name')
                    ->selectRaw('SUM(count) as total_uses')
                    ->selectRaw('COUNT(DISTINCT CASE WHEN chat_type = \'private\' THEN chat_id END) as unique_users')
                    ->selectRaw('MAX(last_used_at) as last_use')
                    ->selectRaw('MD5(command_name) as id')
                    ->groupBy('command_name')
                    ->orderByDesc('total_uses')
                    ->limit(10)
            )
            ->defaultKeySort(false)
            ->columns([
                TextColumn::make('command_name')
                    ->label('الأمر')
                    ->searchable()
                    ->weight('bold')
                    ->copyable()
                    ->copyMessage('تم نسخ الأمر'),

                TextColumn::make('total_uses')
                    ->label('عدد الاستخدامات')
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(fn ($state) => number_format($state)),

                TextColumn::make('unique_users')
                    ->label('المستخدمون الفريدون')
                    ->badge()
                    ->color('success')
                    ->formatStateUsing(fn ($state) => number_format($state)),

                TextColumn::make('last_use')
                    ->label('آخر استخدام')
                    ->dateTime('Y-m-d H:i')
                    ->since()
                    ->description(fn ($record) => $record->last_use ? \Carbon\Carbon::parse($record->last_use)->locale('ar')->diffForHumans() : '—'),
            ])
            ->paginated(false);
    }

    public function getTableRecordKey($record): string
    {
        return $record->command_name ?? md5(json_encode($record));
    }
}
