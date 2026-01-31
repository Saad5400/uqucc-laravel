<?php

namespace App\Models\PrivateTutor;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class PrivateTutor extends Model implements Sortable
{
    use LogsActivity, SortableTrait;

    protected $table = 'private_tutors';

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('private_tutors_data'));
        static::deleted(fn () => Cache::forget('private_tutors_data'));
    }

    protected $fillable = [
        'name',
        'url',
        'order',
    ];

    protected $casts = [
        'order' => 'integer',
    ];

    public array $sortable = [
        'order_column_name' => 'order',
        'sort_when_creating' => true,
    ];

    /**
     * Get the courses taught by this tutor
     */
    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(
            PrivateTutorCourse::class,
            'private_tutor_private_tutor_course',
            'private_tutor_id',
            'private_tutor_course_id'
        )->withTimestamps();
    }

    /**
     * Configure activity logging options
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'url', 'order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
