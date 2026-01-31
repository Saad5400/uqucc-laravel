<?php

namespace App\Filament\Resources\PrivateTutorCourses\Pages;

use App\Filament\Resources\PrivateTutorCourses\PrivateTutorCourseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPrivateTutorCourse extends EditRecord
{
    protected static string $resource = PrivateTutorCourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
