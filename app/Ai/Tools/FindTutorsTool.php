<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\GatedByAiSettings;
use App\Models\PrivateTutor\PrivateTutor;
use App\Models\PrivateTutor\PrivateTutorCourse;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Looks up private tutors (المدرسون الخصوصيون) by course name/code or tutor
 * name. Courses and tutors are eager-loaded both ways so a lookup never
 * N+1s. Read-only.
 */
class FindTutorsTool implements Tool
{
    use GatedByAiSettings;

    private const MAX_MATCHES = 10;

    public function description(): Stringable|string
    {
        return 'Find private tutors (البحث عن المدرسين الخصوصيين لطلاب كلية الحاسبات) by course name or code, or by tutor name. '
            .'Course and tutor names are stored in Arabic — prefer Arabic terms. '
            .'Returns each matching course with its tutors (and their contact links), and each matching tutor with the courses they teach. Read-only.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->aiToolsAreDisabled()) {
            return $this->aiDisabledReply();
        }

        $term = trim($request->string('query')->toString());

        if (mb_strlen($term) < 2) {
            return 'يرجى إدخال اسم مقرر أو مدرس من حرفين على الأقل. Provide a course or tutor name of at least 2 characters.';
        }

        $like = '%'.$term.'%';
        $lines = [];

        $courses = PrivateTutorCourse::query()
            ->whereLike('name', $like)
            ->with(['tutors' => fn (BelongsToMany $tutors) => $tutors->orderBy('order')])
            ->orderBy('order')
            ->limit(self::MAX_MATCHES)
            ->get();

        foreach ($courses as $course) {
            $tutorNames = $course->tutors
                ->map(fn (PrivateTutor $tutor): string => $this->tutorLabel($tutor))
                ->implode('، ');

            $lines[] = $course->tutors->isEmpty()
                ? "- المقرر \"{$course->name}\": لا يوجد مدرسون مسجلون حالياً"
                : "- المقرر \"{$course->name}\": {$tutorNames}";
        }

        $tutors = PrivateTutor::query()
            ->whereLike('name', $like)
            ->with(['courses' => fn (BelongsToMany $courses) => $courses->orderBy('order')])
            ->orderBy('order')
            ->limit(self::MAX_MATCHES)
            ->get();

        foreach ($tutors as $tutor) {
            $courseNames = $tutor->courses->pluck('name')->implode('، ');

            $lines[] = $courseNames === ''
                ? '- المدرس '.$this->tutorLabel($tutor).': بدون مقررات مسجلة'
                : '- المدرس '.$this->tutorLabel($tutor).": يدرّس: {$courseNames}";
        }

        if ($lines === []) {
            return "لا توجد نتائج لـ \"{$term}\". No tutors or courses matched — try a shorter Arabic course name.";
        }

        return "نتائج البحث عن مدرسين خصوصيين لـ \"{$term}\":\n".implode("\n", $lines);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Course name/code or tutor name to search for (اسم المقرر أو رمزه أو اسم المدرس), at least 2 characters.')
                ->required(),
        ];
    }

    private function tutorLabel(PrivateTutor $tutor): string
    {
        return $tutor->url
            ? "{$tutor->name} ({$tutor->url})"
            : (string) $tutor->name;
    }
}
