<?php

namespace App\Filament\Pages;

use App\Settings\TelegramSettings;
use BackedEnum;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Schema;
use UnitEnum;

class ManageTelegramSettings extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $settings = TelegramSettings::class;

    protected static ?string $navigationLabel = 'إعدادات التليجرام';

    protected static ?string $title = 'إعدادات التليجرام';

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 100;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('إعدادات إدارة الصفحات')
                    ->description('تحكم في أوامر إدارة الصفحات عبر التليجرام')
                    ->schema([
                        TagsInput::make('page_management_allowed_chat_ids')
                            ->label('معرّفات المحادثات المسموح لها')
                            ->helperText('أدخل معرّفات المحادثات (Chat IDs) المسموح لها باستخدام أوامر إدارة الصفحات. اتركها فارغة للسماح لجميع المحادثات.')
                            ->placeholder('أضف معرّف محادثة...')
                            ->splitKeys(['Tab', 'Enter', ' ', ','])
                            ->reorderable(),

                        Toggle::make('page_management_auto_delete_messages')
                            ->label('حذف رسائل إدارة الصفحات تلقائياً')
                            ->helperText('عند التفعيل، سيتم حذف رسائل أوامر إدارة الصفحات تلقائياً بعد اكتمال العملية.')
                            ->default(true),
                    ]),
            ]);
    }
}
