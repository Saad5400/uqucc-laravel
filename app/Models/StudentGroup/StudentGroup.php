<?php

namespace App\Models\StudentGroup;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

/**
 * One Telegram group a student can be admitted to, identified by what it is
 * for rather than by a typed-in name: a {@see Major} and a {@see Branch}
 * inside a {@see Cohort}.
 *
 * A group with no major is that intake's **general** group — the one list
 * published to every newcomer before they know their programme. Everything
 * else is specialized, and the pair (major, branch) is what a student actually
 * picks on the public page.
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
        'student_cohort_id',
        'major',
        'branch',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'student_cohort_id' => 'integer',
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

    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class, 'student_cohort_id');
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
            ->logOnly(['student_cohort_id', 'major', 'branch', 'is_active', 'order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
