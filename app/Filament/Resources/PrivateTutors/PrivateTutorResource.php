<?php

namespace App\Filament\Resources\PrivateTutors;

use App\Filament\Resources\PrivateTutors\Pages\CreatePrivateTutor;
use App\Filament\Resources\PrivateTutors\Pages\EditPrivateTutor;
use App\Filament\Resources\PrivateTutors\Pages\ListPrivateTutors;
use App\Filament\Resources\PrivateTutors\Schemas\PrivateTutorForm;
use App\Filament\Resources\PrivateTutors\Tables\PrivateTutorsTable;
use App\Models\PrivateTutor\PrivateTutor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PrivateTutorResource extends Resource
{
    protected static ?string $model = PrivateTutor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $modelLabel = 'خصوصي';

    protected static ?string $pluralModelLabel = 'الخصوصيين';

    protected static ?string $navigationLabel = 'الخصوصيين';

    protected static ?string $navigationGroup = 'الخصوصيين';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('manage-private-tutors') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('manage-private-tutors') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('manage-private-tutors') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->can('manage-private-tutors') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return PrivateTutorForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PrivateTutorsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\CoursesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrivateTutors::route('/'),
            'create' => CreatePrivateTutor::route('/create'),
            'edit' => EditPrivateTutor::route('/{record}/edit'),
        ];
    }
}
