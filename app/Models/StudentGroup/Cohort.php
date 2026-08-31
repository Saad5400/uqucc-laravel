<?php

namespace App\Models\StudentGroup;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
 *
 * `shows_major_groups` turns the per-programme step off on the public page for
 * an intake that is joined through its general group alone. The programme
 * groups and their supervisors are kept intact — the page just stops offering
 * them, so the setting is reversible without re-entering anything.
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

        /*
         * Groups reach an intake through a pivot, so dropping the intake would
         * otherwise leave its groups behind with no owner. A group another
         * intake still shares is only detached — deleting دفعة ٤٦ must not take
         * the programme groups away from دفعة ٤٧ — and one nobody else holds is
         * deleted along with its supervisors.
         */
        static::deleting(function (self $cohort): void {
            $cohort->groups()->get()->each(function (StudentGroup $group) use ($cohort): void {
                if ($group->cohorts()->count() > 1) {
                    $group->cohorts()->detach($cohort->id);

                    return;
                }

                $group->delete();
            });
        });
    }

    protected $fillable = [
        'name',
        'description',
        'note',
        'requirements',
        'is_active',
        'is_featured',
        'shows_major_groups',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'requirements' => 'array',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'shows_major_groups' => 'boolean',
            'order' => 'integer',
        ];
    }

    public array $sortable = [
        'order_column_name' => 'order',
        'sort_when_creating' => true,
    ];

    /**
     * The groups a student of this intake can be admitted to — its global group
     * plus one per programme and branch. A group may be shared with another
     * intake, so this is a pivot rather than a foreign key.
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(StudentGroup::class, 'student_group_cohort', 'student_cohort_id', 'student_group_id')
            ->withTimestamps();
    }

    /**
     * Configure activity logging options
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'description', 'note', 'requirements', 'is_active', 'is_featured', 'shows_major_groups', 'order'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
