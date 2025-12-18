<?php

namespace App\Filament\Forms\Blocks;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\RichContentCustomBlock;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class CollapsibleBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'collapsible';
    }

    public static function getLabel(): string
    {
        return 'قسم قابل للطي';
    }

    public static function getIcon(): ?string
    {
        return Heroicon::QuestionMarkCircle;
    }

    public static function configureEditorAction(Action $action): Action
    {
        return $action
            ->modalHeading('إضافة قسم قابل للطي')
            ->schema([
                TextInput::make('question')
                    ->label('العنوان')
                    ->required()
                    ->maxLength(200),
                RichEditor::make('answer')
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
        return view('rich-content.blocks.collapsible', [
            'question' => $config['question'] ?? '',
            'answer' => new HtmlString($config['answer'] ?? ''),
        ])->render();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function toPreviewHtml(array $config): string
    {
        return static::toHtml($config, []);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function getPreviewLabel(array $config): string
    {
        return $config['question'] ?? static::getLabel();
    }
}
