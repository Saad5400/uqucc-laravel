<?php

namespace App\Ai\Admin\Actions\Quiz;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Ai\Quiz\QuizAuthor;
use App\Models\DailyQuiz;
use App\Models\User;
use App\Support\QuizContentHtml;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Saad\AiKit\Approvals\Classified\FieldWidget;

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
        return 'Edit a not-yet-posted daily quiz. '
            .'Provide quiz_id (from get_daily_quiz), question, exactly four distinct options, '
            .'correct_option (0-3 index into options) and an optional explanation. '
            .'The question is a small HTML fragment (preamble + code + the question) rendered to an image; '
            .'write Arabic paragraphs in <p dir="rtl"> and any code in <pre dir="ltr"><code>, each on its own line. '
            .'The four options are plain text drawn in the image; the Telegram poll shows only generic 1–4 choices. '
            .'The optional hint / obvious_hint are the two teasers the reminder bot sends mid-window and just '
            .'before the question closes. '
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
                ->description('The question as a small HTML fragment (preamble + code + question) rendered to an image; '
                    .'<p dir="rtl"> for Arabic, <pre dir="ltr"><code> for code. Max '.QuizAuthor::MAX_QUESTION_CHARS.' chars of text.')
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
            'hint' => $schema->string()
                ->description('Optional non-spoiler teaser the bot sends mid-window to revive participation (max '
                    .QuizAuthor::MAX_HINT_CHARS.' chars). Empty string to clear it.'),
            'obvious_hint' => $schema->string()
                ->description('Optional blunter hint sent with the last call before the question closes (max '
                    .QuizAuthor::MAX_HINT_CHARS.' chars). Empty string to clear it.'),
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

        $question = QuizContentHtml::sanitize((string) ($input['question'] ?? ''));

        if (QuizContentHtml::textLength($question) === 0 || QuizContentHtml::textLength($question) > QuizAuthor::MAX_QUESTION_CHARS) {
            throw new AdminActionException('السؤال فارغ أو أطول من الحد ('.QuizAuthor::MAX_QUESTION_CHARS.' حرف).');
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
            'hint' => $this->hint($input, 'hint', $quiz->hint),
            'obvious_hint' => $this->hint($input, 'obvious_hint', $quiz->obvious_hint),
        ];
    }

    /**
     * A reminder teaser: absent means "leave as it is" (the model is not
     * required to resend hints it did not touch), an empty string clears it.
     *
     * @param  array<string, mixed>  $input
     */
    private function hint(array $input, string $key, ?string $current): ?string
    {
        if (! array_key_exists($key, $input) || $input[$key] === null) {
            return $current;
        }

        $hint = trim((string) $input[$key]);

        if (mb_strlen($hint) > QuizAuthor::MAX_HINT_CHARS) {
            throw new AdminActionException('التلميح أطول من الحد ('.QuizAuthor::MAX_HINT_CHARS.' حرف).');
        }

        return $hint === '' ? null : $hint;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'تعديل سؤال يوم '.$normalized['quiz_date'].' ليصبح: «'
            .Str::limit(QuizContentHtml::toPlainText($normalized['question']), 120).'».';
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
            'hint' => $normalized['hint'],
            'obvious_hint' => $normalized['obvious_hint'],
        ]);

        return ActionResult::text('تم حفظ تعديلات سؤال يوم '.$normalized['quiz_date'].'.');
    }

    /**
     * The question is an HTML fragment and the explanation runs to a
     * paragraph — both need an editor with room, whatever length the model
     * happened to send.
     *
     * @return array<string, mixed>
     */
    public function fieldWidgets(): array
    {
        return [
            'question' => FieldWidget::Code,
            'explanation' => FieldWidget::Textarea,
        ];
    }
}
