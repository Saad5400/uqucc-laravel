<?php

namespace App\Models\StudentGroup;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

/**
 * One Telegram group a student can be admitted to, identified by what it is
 * for rather than by a typed-in name: a {@see Major} and a {@see Branch},
 * serving one or more {@see Cohort}s.
 *
 * A group with no major is a **global** group — the one list published to every
 * student of an intake regardless of programme. A student joins BOTH: the
 * global group for their batch and the programme group for their major and
 * branch. Neither replaces the other.
 *
 * Naming is derived, never stored: two admins would otherwise spell the same
 * programme differently and split the filter that students navigate by.
 */
class StudentGroup extends Model implements Sortable
{
    /** @use HasFactory<\Database\Factories\StudentGroup\StudentGroupFactory> */
    use HasFactory, LogsActivity, SortableTrait;

    protected $table = 'student_groups';

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(Cohort::CACHE_KEY));
        static::deleted(fn () => Cache::forget(Cohort::CACHE_KEY));
    }

    protected $fillable = [
        'major',
        'branch',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'major' => Major::class,
            'branch' => Branch::class,
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }

    public array $sortable = [
        'order_column_name' => 'order',
        'sort_when_creating' => true,
    ];

    /**
     * The intakes this group serves. Usually one; the college's programme groups
     * for دفعة ٤٦ و٤٧ serve two, which is why this is not a plain foreign key.
     */
    public function cohorts(): BelongsToMany
    {
        return $this->belongsToMany(Cohort::class, 'student_group_cohort', 'student_group_id', 'student_cohort_id')
            ->withTimestamps();
    }

    public function supervisors(): HasMany
    {
        return $this->hasMany(GroupSupervisor::class, 'student_group_id');
    }

    /** Whether this is the intake's general group rather than a programme one. */
    public function isGeneral(): bool
    {
        return $this->major === null;
    }

    /** What the group is called wherever it is listed. */
    public function displayName(): string
    {
        return $this->major?->label() ?? 'القروب العام';
    }

    /** The name plus its branch, for lists that mix branches together. */
    public function qualifiedName(): string
    {
        return $this->branch === null
            ? $this->displayName()
            : $this->displayName().' — '.$this->branch->shortLabel();
    }

    /**
     * Configure activity logging options
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['major', 'branch', 'is_active', 'order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
