<?php

namespace App\Ai\Admin\Actions\System;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Models\Page;
use App\Models\PageChangeRequest;
use App\Models\PrivateTutor\PrivateTutor;
use App\Models\PrivateTutor\PrivateTutorCourse;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A live snapshot of the site for the assistant to ground itself: the current
 * date/time (with timezone) and a headline inventory (page, tutor, course,
 * pending-review and user counts). Answers "what's today's date?" and "how big
 * is the guide?" without the model guessing. Read-only.
 */
class SiteOverviewAction extends AdminAction
{
    public function name(): string
    {
        return 'site_overview';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'system';
    }

    public function description(): string
    {
        return 'Get the current date and time and a headline inventory of the site '
            .'(التاريخ والوقت الحالي ونظرة عامة على أعداد الصفحات والمدرّسين والمراجعات المعلّقة والمستخدمين). '
            .'Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        return ActionResult::text(static::snapshot());
    }

    /**
     * The shared snapshot text — also injected into the assistant's system
     * prompt each turn so the current date and site scale are always in view.
     */
    public static function snapshot(): string
    {
        $now = now();
        $timezone = (string) config('app.timezone', 'UTC');

        $pages = Page::query()->count();
        $hidden = Page::query()->where('hidden', true)->count();
        $trashed = Page::onlyTrashed()->count();
        $tutors = PrivateTutor::query()->count();
        $courses = PrivateTutorCourse::query()->count();
        $pendingReviews = PageChangeRequest::query()->where('status', PageChangeRequest::STATUS_PENDING)->count();
        $users = User::query()->count();

        return implode("\n", [
            'التاريخ والوقت الحالي: '.$now->format('Y-m-d H:i').' ('.$timezone.'، '.$now->translatedFormat('l').').',
            'نظرة عامة على الموقع:',
            '- الصفحات: '.$pages.' (منها '.$hidden.' مخفية و'.$trashed.' محذوفة).',
            '- المدرّسون الخصوصيون: '.$tutors.' ضمن '.$courses.' مادة.',
            '- تعديلات بانتظار المراجعة: '.$pendingReviews.'.',
            '- مستخدمو اللوحة: '.$users.'.',
        ]);
    }
}
