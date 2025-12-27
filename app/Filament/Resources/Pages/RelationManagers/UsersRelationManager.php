<?php

namespace App\Filament\Resources\Pages\RelationManagers;

use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Actions\AttachAction as TableAttachAction;
use Filament\Tables\Actions\DetachAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'المستخدمون (المؤلفون)';

    protected static ?string $modelLabel = 'مستخدم';

    protected static ?string $pluralModelLabel = 'مستخدمون';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('الاسم')
                    ->required()
                    ->maxLength(255),
                TextInput::make('username')
                    ->label('اسم المستخدم')
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('البريد الإلكتروني')
                    ->email()
                    ->required()
                    ->maxLength(255),
                TextInput::make('url')
                    ->label('الرابط')
                    ->url()
                    ->maxLength(255),
                TextInput::make('avatar')
                    ->label('الصورة الرمزية')
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('الاسم')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('username')
                    ->label('اسم المستخدم')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('البريد الإلكتروني')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('pivot.order')
                    ->label('الترتيب')
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort('pivot.order')
            ->headerActions([
                TableAttachAction::make()
                    ->label('ربط مستخدم')
                    ->preloadRecordSelect()
                    ->form(fn (TableAttachAction $action): array => [
                        $action->getRecordSelect()
                            ->label('المستخدم')
                            ->searchable(['name', 'username', 'email']),
                        TextInput::make('order')
                            ->label('الترتيب')
                            ->numeric()
                            ->default(fn () => $this->getOwnerRecord()->users()->max('order') + 1 ?? 1)
                            ->required(),
                    ]),
            ])
            ->actions([
                EditAction::make()
                    ->label('تعديل')
                    ->form([
                        TextInput::make('order')
                            ->label('الترتيب')
                            ->numeric()
                            ->required(),
                    ]),
                DetachAction::make()
                    ->label('فك الربط'),
            ]);
    }
}
