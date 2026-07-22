<?php

namespace App\Ai\Admin\Actions\Quiz;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Ai\Quiz\QuizAuthor;
use App\Models\DailyQuiz;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Replace a day's quiz with a freshly generated one — «بدّل سؤال اليوم». Any
 * existing `ready` (not yet posted) quiz for the day is deleted and a new one
 * is authored from the rotation, so it can only run for a day whose quiz has
 * not been posted. Runs the (slow, paid) authoring model, gated exactly like
 * the nightly generation.
 */
class RegenerateDailyQuizAction extends AdminAction
{
    public function __construct(private readonly QuizAuthor $author) {}

    public function name(): string
    {
        return 'regenerate_daily_quiz';
    }

    public function category(): string
    {
        return 'quiz';
    }

    public function description(): string
    {
        return 'Regenerate the daily quiz for a day (default today) — «بدّل سؤال اليوم». '
            .'Deletes the current not-yet-posted question and authors a new one from the topic rotation. '
            .'Optional date in YYYY-MM-DD. Only works for a day whose quiz has not been posted yet. '
            .'Runs the paid authoring model.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()
                ->description('The day to regenerate, as YYYY-MM-DD. Defaults to today.'),
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
            } catch (Throwable) {
                throw new AdminActionException('صيغة التاريخ غير صحيحة — استخدم YYYY-MM-DD.');
            }
        }

        if (($reason = $this->author->disabledReason()) !== null) {
            throw new AdminActionException($reason);
        }

        $existing = DailyQuiz::forDate($date);

        if ($existing !== null && ! $existing->isReady()) {
            throw new AdminActionException('لا يمكن استبدال سؤال يوم '.$date->toDateString().' لأنه نُشر بالفعل.');
        }

        return ['date' => $date->toDateString()];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'توليد سؤال جديد ليوم '.$normalized['date'].' (سيُحذف السؤال الحالي إن وُجد).';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $date = Carbon::parse($normalized['date']);
        $existing = DailyQuiz::forDate($date);

        if ($existing !== null && ! $existing->isReady()) {
            throw new AdminActionException('لا يمكن استبدال سؤال يوم '.$normalized['date'].' لأنه نُشر بالفعل.');
        }

        $existing?->delete();

        try {
            $quiz = $this->author->generateForDate($date);
        } catch (Throwable $exception) {
            throw new AdminActionException('تعذّر توليد السؤال: '.$exception->getMessage());
        }

        return ActionResult::text(
            'تم توليد سؤال جديد ليوم '.$normalized['date'].' (id='.$quiz->id."):\n".$quiz->question,
        );
    }
}
