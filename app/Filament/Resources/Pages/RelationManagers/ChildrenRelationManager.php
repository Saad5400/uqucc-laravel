<?php

namespace App\Filament\Resources\Pages\RelationManagers;

use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Filament\Resources\Pages\Tables\PagesTable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ChildrenRelationManager extends RelationManager
{
    protected static ?string $relatedResource = PageResource::class;

    protected static string $relationship = 'children';

    protected static ?string $title = 'الصفحات الفرعية';

    protected static ?string $modelLabel = 'صفحة فرعية';

    protected static ?string $pluralModelLabel = 'صفحات فرعية';

    public function table(Table $table): Table
    {
        return PagesTable::configure($table)
            ->headerActions([
                CreateAction::make()
                    ->url(PageResource::getUrl('create', [
                        'default_parent_id' => $this->ownerRecord->id,
                    ])),
            ]);
    }
}
