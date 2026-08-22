<?php

namespace App\Models\StudentGroup;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

/**
 * An intake the college publishes joining instructions for — «دفعة ٤٨»,
 * «دفعة ٤٦ و٤٧». It owns the prose every one of its groups shares: the welcome
 * blurb, the checklist of what a join request must contain, and the standing
 * warning at the bottom of the announcement.
 *
 * Those live here rather than on each {@see StudentGroup} because one intake
 * publishes a single set of instructions across a dozen or more groups, and
 * copying them per group would mean editing a dozen rows to fix one sentence.
 *
 * `is_featured` marks the intake the page opens on — the one currently taking
 * newcomers. Older intakes stay reachable behind their own tab rather than
 * being deleted, because students who missed the announcement still need them.
 */
class Cohort extends Model implements Sortable
{
    /** @use HasFactory<\Database\Factories\StudentGroup\CohortFactory> */
    use HasFactory, LogsActivity, SortableTrait;

    protected $table = 'student_cohorts';

    /** Cache key for the public page payload; flushed by every write below. */
    public const CACHE_KEY = 'student_groups_data';

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    protected $fillable = [
        'name',
        'description',
        'note',
        'requirements',
        'is_active',
        'is_featured',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'requirements' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'order' => 'integer',
        ];
    }

    public array $sortable = [
        'order_column_name' => 'order',
        'sort_when_creating' => true,
    ];

    /**
     * The groups a student of this intake can be admitted to.
     */
    public function groups(): HasMany
    {
        return $this->hasMany(StudentGroup::class, 'student_cohort_id');
    }

    /**
     * Every supervisor across the intake's groups — for the counts that tell an
     * admin at a glance whether the intake is usable right now.
     */
    public function supervisors(): HasManyThrough
    {
        return $this->hasManyThrough(
            GroupSupervisor::class,
            StudentGroup::class,
            'student_cohort_id',
            'student_group_id',
        );
    }

    /**
     * Configure activity logging options
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'description', 'note', 'requirements', 'is_active', 'is_featured', 'order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
