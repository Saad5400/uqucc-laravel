<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\ActivityLogs\Pages\ViewActivityLog;
use App\Filament\Resources\ActivityLogs\Tables\ActivityLogsTable;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'سجل نشاط';

    protected static ?string $pluralModelLabel = 'سجل الأنشطة';

    protected static ?string $navigationLabel = 'سجل الأنشطة';

    protected static ?int $navigationSort = 10;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view-activity-logs') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ActivityLogsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return parent::infolist($schema)
            ->components([
                Section::make('معلومات النشاط')
                    ->schema([
                        TextEntry::make('log_name')
                            ->label('اسم السجل')
                            ->badge(),
                        TextEntry::make('description')
                            ->label('الوصف'),
                        TextEntry::make('event')
                            ->label('الحدث')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'created' => 'success',
                                'updated' => 'warning',
                                'deleted' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('subject_type')
                            ->label('نوع الموضوع')
                            ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '-'),
                        TextEntry::make('subject_id')
                            ->label('معرّف الموضوع'),
                        TextEntry::make('causer.name')
                            ->label('المستخدم')
                            ->default('-'),
                        TextEntry::make('created_at')
                            ->label('تاريخ الإنشاء')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label('تاريخ التحديث')
                            ->dateTime(),
                    ])
                    ->columns(2),
                Section::make('القيم القديمة (قبل التغيير)')
                    ->schema([
                        TextEntry::make('properties')
                            ->label('')
                            ->formatStateUsing(function ($state, $record): string {
                                if (! $state) {
                                    return '-';
                                }

                                $old = $state['old'] ?? null;

                                if (! $old || empty($old)) {
                                    return $record->event === 'created' ? 'لا توجد قيم قديمة (تم الإنشاء)' : '-';
                                }

                                $output = '';
                                foreach ($old as $key => $value) {
                                    $displayValue = is_null($value) ? 'null' : (is_bool($value) ? ($value ? 'true' : 'false') : $value);
                                    $output .= "<strong>{$key}:</strong> {$displayValue}<br>";
                                }

                                return $output;
                            })
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->hidden(fn ($record) => $record->event === 'created'),
                Section::make('القيم الجديدة (بعد التغيير)')
                    ->schema([
                        TextEntry::make('properties')
                            ->label('')
                            ->formatStateUsing(function ($state, $record): string {
                                if (! $state) {
                                    return '-';
                                }

                                $attributes = $state['attributes'] ?? null;

                                if (! $attributes || empty($attributes)) {
                                    return $record->event === 'deleted' ? 'لا توجد قيم جديدة (تم الحذف)' : '-';
                                }

                                $output = '';
                                foreach ($attributes as $key => $value) {
                                    $displayValue = is_null($value) ? 'null' : (is_bool($value) ? ($value ? 'true' : 'false') : $value);
                                    $output .= "<strong>{$key}:</strong> {$displayValue}<br>";
                                }

                                return $output;
                            })
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->hidden(fn ($record) => $record->event === 'deleted'),
                Section::make('جميع الخصائص (JSON)')
                    ->schema([
                        TextEntry::make('properties')
                            ->label('')
                            ->formatStateUsing(fn ($state): string => $state ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '-')
                            ->html()
                            ->extraAttributes(['class' => 'font-mono text-sm'])
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->collapsed(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
            'view' => ViewActivityLog::route('/{record}'),
        ];
    }
}
