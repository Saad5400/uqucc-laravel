<?php

namespace App\Filament\Resources\PrivateTutors\Pages;

use App\Filament\Resources\PrivateTutors\PrivateTutorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPrivateTutors extends ListRecords
{
    protected static string $resource = PrivateTutorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
