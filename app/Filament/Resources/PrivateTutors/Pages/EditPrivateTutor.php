<?php

namespace App\Filament\Resources\PrivateTutors\Pages;

use App\Filament\Resources\PrivateTutors\PrivateTutorResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPrivateTutor extends EditRecord
{
    protected static string $resource = PrivateTutorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
