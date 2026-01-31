<?php

namespace App\Filament\Resources\PrivateTutorCourses\Pages;

use App\Filament\Resources\PrivateTutorCourses\PrivateTutorCourseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPrivateTutorCourses extends ListRecords
{
    protected static string $resource = PrivateTutorCourseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
