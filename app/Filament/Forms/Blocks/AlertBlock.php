<?php

namespace App\Filament\Forms\Blocks;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class AlertBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'alert';
    }

    public static function getLabel(): string
    {
        return 'تنبيه';
    }

    public static function getIcon(): ?string
    {
        return Heroicon::BellAlert;
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('إضافة تنبيه')
            ->schema([
                TextInput::make('icon')
                    ->label('أيقونة Iconify (مثال: solar:info-circle-linear)')
                    ->placeholder('solar:info-circle-linear')
                    ->maxLength(100),
                RichEditor::make('content')
                    ->label('المحتوى')
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): string
    {
        return view('rich-content.blocks.alert', [
            'icon' => $config['icon'] ?? null,
            'content' => new HtmlString($config['content'] ?? ''),
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        return static::toHtml($config, []);
    }
}
