<?php

namespace App\Filament\Resources\PrivateTutorCourses;

use App\Filament\Resources\PrivateTutorCourses\Pages\CreatePrivateTutorCourse;
use App\Filament\Resources\PrivateTutorCourses\Pages\EditPrivateTutorCourse;
use App\Filament\Resources\PrivateTutorCourses\Pages\ListPrivateTutorCourses;
use App\Filament\Resources\PrivateTutorCourses\Schemas\PrivateTutorCourseForm;
use App\Filament\Resources\PrivateTutorCourses\Tables\PrivateTutorCoursesTable;
use App\Models\PrivateTutor\PrivateTutorCourse;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PrivateTutorCourseResource extends Resource
{
    protected static ?string $model = PrivateTutorCourse::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $modelLabel = 'مادة';

    protected static ?string $pluralModelLabel = 'المواد';

    protected static ?string $navigationLabel = 'المواد';

    protected static string|null|\UnitEnum $navigationGroup = 'الخصوصيين';

    protected static ?int $navigationSort = 2;

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
        return PrivateTutorCourseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PrivateTutorCoursesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TutorsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPrivateTutorCourses::route('/'),
            'create' => CreatePrivateTutorCourse::route('/create'),
            'edit' => EditPrivateTutorCourse::route('/{record}/edit'),
        ];
    }
}
