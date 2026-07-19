<?php

namespace App\Ai\Admin\Actions\Analytics;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit trail as text: the most recent Spatie activity-log entries, newest
 * first, each with its date, causer, description/event and subject
 * (سجل النشاط: أحدث القيود مع التاريخ والفاعل والوصف والكائن المتأثر).
 * Optional filters by log name, event and subject type mirror
 * {@see \App\Http\Controllers\Manage\ActivityLogController}. Read-only.
 */
class ListActivityLogAction extends AdminAction
{
    /** Default number of entries returned. */
    private const DEFAULT_LIMIT = 20;

    /** Upper bound on the number of entries returned. */
    private const MAX_LIMIT = 50;

    public function name(): string
    {
        return 'list_activity_log';
    }

    public function requiredAbility(): ?string
    {
        return 'view-activity-logs';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'analytics';
    }

    public function description(): string
    {
        return 'List the most recent audit-log entries, newest first (سجل النشاط: أحدث القيود مع التاريخ والفاعل '
            .'والوصف والكائن المتأثر). Each entry has its date, the causer (if any), the description/event, and the '
            .'subject type and id. Optional limit (default 20, capped at 50), log_name, event and subject_type filters. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Optional number of entries to return (default 20, capped at 50).'),
            'log_name' => $schema->string()
                ->description('Optional filter on the log name (e.g. "default").'),
            'event' => $schema->string()
                ->description('Optional filter on the event (e.g. "created", "updated", "deleted").'),
            'subject_type' => $schema->string()
                ->description('Optional filter on the subject class basename (e.g. "Page", "User", "PrivateTutor").'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $limit = (int) ($input['limit'] ?? self::DEFAULT_LIMIT);

        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }

        return [
            'limit' => min($limit, self::MAX_LIMIT),
            'log_name' => trim((string) ($input['log_name'] ?? '')),
            'event' => trim((string) ($input['event'] ?? '')),
            'subject_type' => trim((string) ($input['subject_type'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $limit = (int) $normalized['limit'];
        $logName = (string) $normalized['log_name'];
        $event = (string) $normalized['event'];
        $subjectType = (string) $normalized['subject_type'];

        $activities = Activity::query()
            ->with('causer')
            ->when($logName !== '', fn (Builder $query) => $query->where('log_name', $logName))
            ->when($event !== '', fn (Builder $query) => $query->where('event', $event))
            ->when($subjectType !== '', function (Builder $query) use ($subjectType): void {
                $matching = Activity::query()
                    ->whereNotNull('subject_type')
                    ->distinct()
                    ->pluck('subject_type')
                    ->filter(fn (string $type): bool => class_basename($type) === $subjectType)
                    ->values();

                $query->whereIn('subject_type', $matching);
            })
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get();

        if ($activities->isEmpty()) {
            return ActionResult::text('لا توجد قيود في سجل النشاط مطابقة.');
        }

        $lines = $activities
            ->map(fn (Activity $activity): string => $this->renderActivity($activity))
            ->all();

        return ActionResult::text(
            "سجل النشاط (التاريخ | الفاعل | الوصف | الحدث | الكائن):\n".implode("\n", $lines),
        );
    }

    private function renderActivity(Activity $activity): string
    {
        $subject = $activity->subject_type !== null
            ? class_basename($activity->subject_type).($activity->subject_id !== null ? ' #'.$activity->subject_id : '')
            : '—';

        return sprintf(
            '- %s | %s | %s | %s | %s',
            $activity->created_at?->toDateTimeString() ?? '—',
            $activity->causer?->getAttribute('name') ?? '—',
            $activity->description !== '' ? $activity->description : '—',
            $activity->event ?? '—',
            $subject,
        );
    }
}
