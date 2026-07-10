<?php

namespace App\Filament\Resources\TelegramChatSettings;

use App\Filament\Resources\TelegramChatSettings\Pages\ListTelegramChatSettings;
use App\Filament\Resources\TelegramChatSettings\Tables\TelegramChatSettingsTable;
use App\Models\TelegramChatSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Admin visibility (and override) for the bot's per-chat AI activation.
 * Rows are created by the /ai_on and /ai_off commands inside Telegram; this
 * resource lists them with a toggle so operators can enable or disable the
 * assistant for any chat from the panel.
 */
class TelegramChatSettingResource extends Resource
{
    protected static ?string $model = TelegramChatSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $modelLabel = 'محادثة تليجرام';

    protected static ?string $pluralModelLabel = 'محادثات التليجرام';

    protected static ?string $navigationLabel = 'ذكاء بوت التليجرام';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return TelegramChatSettingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTelegramChatSettings::route('/'),
        ];
    }
}
