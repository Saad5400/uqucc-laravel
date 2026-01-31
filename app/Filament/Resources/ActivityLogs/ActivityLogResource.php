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
                        TextEntry::make('old_values')
                            ->label('')
                            ->state(function ($record): string {
                                if (! $record->properties) {
                                    return '-';
                                }

                                $properties = is_array($record->properties) ? $record->properties : $record->properties->toArray();
                                $old = $properties['old'] ?? null;

                                if (! $old || empty($old)) {
                                    return $record->event === 'created' ? 'لا توجد قيم قديمة (تم الإنشاء)' : 'لا توجد تغييرات';
                                }

                                $output = '<div style="line-height: 1.8;">';
                                foreach ($old as $key => $value) {
                                    $displayValue = is_null($value) ? '<em>null</em>' : (is_bool($value) ? ($value ? '<em>true</em>' : '<em>false</em>') : htmlspecialchars($value));
                                    $output .= "<div><strong>{$key}:</strong> {$displayValue}</div>";
                                }
                                $output .= '</div>';

                                return $output;
                            })
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->hidden(fn ($record) => $record->event === 'created'),
                Section::make('القيم الجديدة (بعد التغيير)')
                    ->schema([
                        TextEntry::make('new_values')
                            ->label('')
                            ->state(function ($record): string {
                                if (! $record->properties) {
                                    return '-';
                                }

                                $properties = is_array($record->properties) ? $record->properties : $record->properties->toArray();
                                $attributes = $properties['attributes'] ?? null;

                                if (! $attributes || empty($attributes)) {
                                    return $record->event === 'deleted' ? 'لا توجد قيم جديدة (تم الحذف)' : 'لا توجد تغييرات';
                                }

                                $output = '<div style="line-height: 1.8;">';
                                foreach ($attributes as $key => $value) {
                                    $displayValue = is_null($value) ? '<em>null</em>' : (is_bool($value) ? ($value ? '<em>true</em>' : '<em>false</em>') : htmlspecialchars($value));
                                    $output .= "<div><strong>{$key}:</strong> {$displayValue}</div>";
                                }
                                $output .= '</div>';

                                return $output;
                            })
                            ->html()
                            ->columnSpanFull(),
                    ])
                    ->collapsible()
                    ->hidden(fn ($record) => $record->event === 'deleted'),
                Section::make('جميع الخصائص (JSON)')
                    ->schema([
                        TextEntry::make('all_properties')
                            ->label('')
                            ->state(function ($record): string {
                                if (! $record->properties) {
                                    return '-';
                                }

                                $properties = is_array($record->properties) ? $record->properties : $record->properties->toArray();
                                $output = '';

                                if (isset($properties['old']) && ! empty($properties['old'])) {
                                    $output .= '<strong style="color: #ef4444;">القيم القديمة (Old Values):</strong><br>';
                                    $output .= '<pre style="margin: 10px 0; padding: 10px; background: #1f2937; border-radius: 5px; overflow-x: auto;">';
                                    $output .= htmlspecialchars(json_encode($properties['old'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                    $output .= '</pre>';
                                }

                                if (isset($properties['attributes']) && ! empty($properties['attributes'])) {
                                    $output .= '<strong style="color: #10b981;">القيم الجديدة (New Values):</strong><br>';
                                    $output .= '<pre style="margin: 10px 0; padding: 10px; background: #1f2937; border-radius: 5px; overflow-x: auto;">';
                                    $output .= htmlspecialchars(json_encode($properties['attributes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                    $output .= '</pre>';
                                }

                                return $output ?: '-';
                            })
                            ->html()
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
