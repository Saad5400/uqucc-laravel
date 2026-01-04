<?php

namespace App\Filament\Widgets;

use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Artisan;

class CacheClearWidget extends Widget
{
    protected string $view = 'filament.widgets.cache-clear-widget';

    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    public function clearCache(): void
    {
        Artisan::call('cache:clear');

        Notification::make()
            ->title('تم مسح الذاكرة المؤقتة بنجاح')
            ->success()
            ->send();
    }
}
