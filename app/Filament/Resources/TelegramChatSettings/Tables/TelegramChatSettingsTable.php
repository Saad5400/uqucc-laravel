<?php

namespace App\Filament\Resources\TelegramChatSettings\Tables;

use App\Models\TelegramChatSetting;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TelegramChatSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('المحادثة')
                    ->searchable()
                    ->placeholder('بدون اسم')
                    ->description(fn (TelegramChatSetting $record): string => (string) $record->chat_id),

                TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::typeLabel($state))
                    ->color('gray'),

                ToggleColumn::make('ai_enabled')
                    ->label('المساعد الذكي'),

                TextColumn::make('enabled_by')
                    ->label('فعّله')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('updated_at')
                    ->label('آخر تحديث')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('ai_enabled')
                    ->label('المساعد الذكي')
                    ->trueLabel('مفعل')
                    ->falseLabel('موقوف'),
            ])
            ->recordActions([
                Action::make('reset_conversation')
                    ->label('محادثة جديدة')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->visible(fn (TelegramChatSetting $record): bool => filled($record->conversation_id))
                    ->requiresConfirmation()
                    ->modalHeading('بدء محادثة جديدة')
                    ->modalDescription('سيبدأ المساعد محادثة جديدة في هذه الدردشة وينسى سياق المحادثة الحالية.')
                    ->action(function (TelegramChatSetting $record): void {
                        $record->update(['conversation_id' => null]);

                        Notification::make()
                            ->title('تمت إعادة تعيين المحادثة')
                            ->success()
                            ->send();
                    }),

                DeleteAction::make()
                    ->label('حذف'),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    private static function typeLabel(string $type): string
    {
        return match ($type) {
            'private' => 'خاص',
            'group' => 'مجموعة',
            'supergroup' => 'مجموعة كبيرة',
            'channel' => 'قناة',
            default => $type,
        };
    }
}
