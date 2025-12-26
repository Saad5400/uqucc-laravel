<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Operation;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('الاسم')
                    ->required(),
                TextInput::make('email')
                    ->label('البريد الإلكتروني')
                    ->email()
                    ->required(),
                TextInput::make('telegram_id')
                    ->label('معرّف تيليجرام')
                    ->helperText('يتم تعيينه تلقائياً عند تسجيل الدخول عبر البوت')
                    ->disabled()
                    ->dehydrated(false),
                DateTimePicker::make('email_verified_at')
                    ->label('تاريخ التحقق من البريد الإلكتروني'),
                Toggle::make('change_password')
                    ->label('تغيير كلمة المرور')
                    ->visible(fn ($operation) => $operation == Operation::Edit->value)
                    ->reactive(),
                Group::make()
                    ->schema(function (Get $get, $operation) {
                        if ($operation == Operation::Edit->value && ! $get('change_password')) {
                            return [];
                        }

                        return [
                            TextInput::make('password')
                                ->label('كلمة المرور')
                                ->password()
                                ->revealable()
                                ->minLength(8)
                                ->required(fn ($operation) => $operation == Operation::Create->value)
                                ->dehydrated(fn ($state) => filled($state)),
                        ];
                    }),
                Select::make('roles')
                    ->label('الأدوار')
                    ->relationship('roles', 'name')
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->visible(fn () => auth()->user()?->can('assign-roles')),
            ]);
    }
}
