<?php

namespace App\Filament\Pages;

use App\Settings\AiSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use UnitEnum;

class ManageAiSettings extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static string $settings = AiSettings::class;

    protected static ?string $navigationLabel = 'إعدادات الذكاء الاصطناعي';

    protected static ?string $title = 'إعدادات الذكاء الاصطناعي';

    protected static string|UnitEnum|null $navigationGroup = 'الإعدادات';

    protected static ?int $navigationSort = 101;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('التفعيل')
                    ->description('مفاتيح تشغيل ميزات الذكاء الاصطناعي')
                    ->schema([
                        Toggle::make('ai_enabled')
                            ->label('تفعيل الذكاء الاصطناعي')
                            ->helperText('مفتاح التشغيل الرئيسي. عند إيقافه تتعطل جميع ميزات الذكاء الاصطناعي بغض النظر عن المفاتيح الأخرى.'),

                        Toggle::make('search_enabled')
                            ->label('البحث الذكي')
                            ->helperText('تفعيل البحث المعزز بالذكاء الاصطناعي في الموقع.'),

                        Toggle::make('assistant_enabled')
                            ->label('المساعد الذكي')
                            ->helperText('تفعيل المساعد الذكي للزوار.'),

                        Toggle::make('telegram_ai_enabled')
                            ->label('ذكاء بوت التليجرام')
                            ->helperText('تفعيل الردود الذكية في بوت التليجرام.'),

                        Toggle::make('admin_copilot_enabled')
                            ->label('مساعد لوحة الإدارة')
                            ->helperText('تفعيل المساعد الذكي داخل لوحة الإدارة.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('النماذج')
                    ->description('معرّفات النماذج المستخدمة عبر OpenRouter')
                    ->schema([
                        TextInput::make('chat_model')
                            ->label('نموذج المحادثة')
                            ->extraAttributes(['dir' => 'ltr'])
                            ->required(),

                        TextInput::make('vision_model')
                            ->label('نموذج الرؤية')
                            ->extraAttributes(['dir' => 'ltr'])
                            ->required(),

                        TextInput::make('embedding_model')
                            ->label('نموذج التضمين (Embeddings)')
                            ->extraAttributes(['dir' => 'ltr'])
                            ->required(),
                    ])
                    ->columns(1)
                    ->columnSpanFull(),

                Section::make('التكلفة والحدود')
                    ->description('ضوابط التكلفة وحدود الاستخدام اليومية')
                    ->schema([
                        TextInput::make('daily_budget_usd')
                            ->label('الميزانية اليومية (دولار)')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.5)
                            ->required(),

                        TextInput::make('per_session_rate_limit')
                            ->label('حد الرسائل اليومي لكل جلسة')
                            ->integer()
                            ->minValue(1)
                            ->required(),

                        TextInput::make('per_conversation_rate_limit')
                            ->label('حد الرسائل اليومي لكل محادثة تليجرام')
                            ->integer()
                            ->minValue(1)
                            ->required(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
            ]);
    }
}
