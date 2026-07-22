<?php

namespace App\Ai\Admin\Actions\Quiz;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Ai\Quiz\QuizAuthor;
use App\Models\DailyQuiz;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Edit a not-yet-posted daily quiz, mirroring
 * {@see \App\Http\Controllers\Manage\DailyQuizController::update()}. Only a
 * `ready` quiz can change — a posted one is history the scoring depends on.
 * Send the full question: text, exactly four distinct options, the
 * correct_option index (0–3) and an optional explanation. All obey Telegram's
 * quiz-poll limits.
 */
class UpdateDailyQuizAction extends AdminAction
{
    public function name(): string
    {
        return 'update_daily_quiz';
    }

    public function category(): string
    {
        return 'quiz';
    }

    public function description(): string
    {
        return 'Edit a not-yet-posted daily quiz (تعديل سؤال اليوم قبل نشره). '
            .'Provide quiz_id (from get_daily_quiz), question, exactly four distinct options, '
            .'correct_option (0-3 index into options) and an optional explanation. '
            .'Only a ready (unposted) quiz can be edited.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'quiz_id' => $schema->integer()
                ->description('The id of the quiz to edit, from get_daily_quiz.')
                ->required(),
            'question' => $schema->string()
                ->description('The question text (max '.QuizAuthor::MAX_QUESTION_CHARS.' chars).')
                ->required(),
            'options' => $schema->array()
                ->items($schema->string())
                ->description('Exactly four distinct answer options (each max '.QuizAuthor::MAX_OPTION_CHARS.' chars).')
                ->required(),
            'correct_option' => $schema->integer()
                ->description('The 0-based index (0-3) of the correct option.')
                ->required(),
            'explanation' => $schema->string()
                ->description('Optional explanation shown after answering (max '.QuizAuthor::MAX_EXPLANATION_CHARS.' chars).'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $quiz = DailyQuiz::query()->find($input['quiz_id'] ?? null);

        if ($quiz === null) {
            throw new AdminActionException('لا يوجد سؤال بهذا المعرّف. استخدم get_daily_quiz للتأكد.');
        }

        if (! $quiz->isReady()) {
            throw new AdminActionException('لا يمكن تعديل سؤال بعد نشره.');
        }

        $question = trim((string) ($input['question'] ?? ''));

        if ($question === '' || mb_strlen($question) > QuizAuthor::MAX_QUESTION_CHARS) {
            throw new AdminActionException('السؤال فارغ أو أطول من حد تيليجرام ('.QuizAuthor::MAX_QUESTION_CHARS.' حرف).');
        }

        $options = $input['options'] ?? null;

        if (! is_array($options) || count($options) !== 4) {
            throw new AdminActionException('يجب أن تكون الخيارات أربعة بالضبط.');
        }

        $options = array_values(array_map(fn (mixed $option): string => trim((string) $option), $options));

        foreach ($options as $option) {
            if ($option === '' || mb_strlen($option) > QuizAuthor::MAX_OPTION_CHARS) {
                throw new AdminActionException('أحد الخيارات فارغ أو أطول من حد تيليجرام ('.QuizAuthor::MAX_OPTION_CHARS.' حرف).');
            }
        }

        if (count(array_unique($options)) !== 4) {
            throw new AdminActionException('الخيارات متكررة.');
        }

        $correct = $input['correct_option'] ?? null;

        if (! is_numeric($correct) || (int) $correct < 0 || (int) $correct > 3) {
            throw new AdminActionException('الإجابة الصحيحة يجب أن تكون رقماً بين 0 و3.');
        }

        $explanation = array_key_exists('explanation', $input) && $input['explanation'] !== null
            ? trim((string) $input['explanation'])
            : null;

        if ($explanation !== null && mb_strlen($explanation) > QuizAuthor::MAX_EXPLANATION_CHARS) {
            throw new AdminActionException('الشرح أطول من حد تيليجرام ('.QuizAuthor::MAX_EXPLANATION_CHARS.' حرف).');
        }

        return [
            'quiz_id' => $quiz->id,
            'quiz_date' => $quiz->quiz_date->toDateString(),
            'question' => $question,
            'options' => $options,
            'correct_option' => (int) $correct,
            'explanation' => $explanation === '' ? null : $explanation,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'تعديل سؤال يوم '.$normalized['quiz_date'].' ليصبح: «'.$normalized['question'].'».';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $quiz = DailyQuiz::query()->find($normalized['quiz_id']);

        if ($quiz === null) {
            throw new AdminActionException('السؤال المستهدف لم يعد موجوداً.');
        }

        if (! $quiz->isReady()) {
            throw new AdminActionException('لا يمكن تعديل سؤال بعد نشره.');
        }

        $quiz->update([
            'question' => $normalized['question'],
            'options' => $normalized['options'],
            'correct_option' => $normalized['correct_option'],
            'explanation' => $normalized['explanation'],
        ]);

        return ActionResult::text('تم حفظ تعديلات سؤال يوم '.$normalized['quiz_date'].'.');
    }
}
