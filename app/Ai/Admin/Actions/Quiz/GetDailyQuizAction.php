<?php

namespace App\Ai\Admin\Actions\Quiz;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\DailyQuiz;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;

/**
 * The daily quiz for a given day (default today): its id, status, question,
 * options with the correct one marked, explanation, source topic, and — once
 * posted — turnout and accuracy. Use the returned id with update_daily_quiz.
 * Read-only.
 */
class GetDailyQuizAction extends AdminAction
{
    public function name(): string
    {
        return 'get_daily_quiz';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'quiz';
    }

    public function description(): string
    {
        return 'Get the daily quiz for a day (default today) — id, status (ready/posted/closed), question, '
            .'options with the correct answer marked, explanation, topic, and turnout once posted '
            .'(عرض سؤال اليوم مع حالته وخياراته والإجابة الصحيحة). '
            .'Optional date in YYYY-MM-DD. Use the returned id with update_daily_quiz. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()
                ->description('The day to fetch, as YYYY-MM-DD. Defaults to today.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $date = today();

        if (filled($input['date'] ?? null)) {
            try {
                $date = Carbon::parse((string) $input['date']);
            } catch (\Throwable) {
                throw new AdminActionException('صيغة التاريخ غير صحيحة — استخدم YYYY-MM-DD.');
            }
        }

        return ['date' => $date->toDateString()];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $quiz = DailyQuiz::query()
            ->with('topic:id,name')
            ->withCount([
                'answers',
                'answers as correct_answers_count' => fn ($query) => $query->where('is_correct', true),
            ])
            ->whereDate('quiz_date', $normalized['date'])
            ->first();

        if ($quiz === null) {
            return ActionResult::text(
                'لا يوجد سؤال ليوم '.$normalized['date'].'. '
                .'ولّد واحداً بـ regenerate_daily_quiz إن كان اليوم، أو انتظر التوليد الليلي.',
            );
        }

        $statuses = [
            DailyQuiz::STATUS_READY => 'جاهز (قابل للتعديل قبل النشر)',
            DailyQuiz::STATUS_POSTED => 'منشور (حي في المجموعات)',
            DailyQuiz::STATUS_CLOSED => 'مغلق',
        ];

        $options = collect($quiz->options)
            ->map(fn (string $option, int $index): string => sprintf(
                '  %s %d. %s',
                $index === $quiz->correct_option ? '✅' : '▫️',
                $index,
                $option,
            ))
            ->implode("\n");

        $lines = [
            'سؤال يوم '.$quiz->quiz_date->toDateString().' (id='.$quiz->id.')',
            'الحالة: '.($statuses[$quiz->status] ?? $quiz->status),
            'الموضوع: '.($quiz->topic?->name ?? '—'),
            '',
        ];

        if (filled($quiz->body)) {
            $lines[] = 'المحتوى (body — يُنشر فوق التصويت):';
            $lines[] = $quiz->body;
            $lines[] = '';
        }

        $lines[] = $quiz->question;
        $lines[] = $options;

        if (filled($quiz->explanation)) {
            $lines[] = 'الشرح: '.$quiz->explanation;
        }

        if ($quiz->status !== DailyQuiz::STATUS_READY) {
            $lines[] = 'المشاركون: '.$quiz->answers_count.' — إجابات صحيحة: '.$quiz->correct_answers_count;
        }

        return ActionResult::text(implode("\n", $lines));
    }
}
