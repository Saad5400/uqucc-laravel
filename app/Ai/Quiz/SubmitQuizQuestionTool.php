<?php

namespace App\Ai\Quiz;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The single tool behind daily-question generation. The model calls it with a
 * candidate question; it validates against Telegram's poll limits and the
 * quality rules (four unique options, a valid answer index, balanced option
 * lengths). On any problem it returns the full list so the model — still in the
 * same agentic conversation, with its own previous attempt in context —
 * corrects everything and calls again, instead of a blind stateless retry. On
 * success it captures the normalized payload, which {@see QuizAuthor} reads
 * after the run via {@see accepted()}.
 */
class SubmitQuizQuestionTool implements Tool
{
    /**
     * The last accepted question, or null until one passes validation.
     *
     * @var array{question: string, body: string|null, options: array<int, string>, correct_option: int, explanation: string|null, hint: string|null, obvious_hint: string|null}|null
     */
    private ?array $accepted = null;

    public function name(): string
    {
        return 'submit_quiz_question';
    }

    public function description(): Stringable|string
    {
        return 'أرسل سؤال اليوم المقترح للتحقق منه واعتماده. استدعِ هذه الأداة بالحقول المطلوبة. '
            .'إن أعادت قائمة مشاكل فصحّحها جميعاً واستدعِ الأداة مرة أخرى حتى تُقبل. لا تكتب السؤال في نص عادي.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()
                ->description('نص السؤال القصير المستقل (حتى '.QuizAuthor::MAX_QUESTION_CHARS.' حرف).')
                ->required(),
            'body' => $schema->string()
                ->description('كود/سيناريو أو مقدمة تعليمية تُنشر فوق التصويت، أو "" عند عدم الحاجة (حتى '.QuizAuthor::MAX_BODY_CHARS.' حرف).'),
            'options' => $schema->array()->items($schema->string())->min(4)->max(4)
                ->description('أربعة خيارات مختلفة متقاربة الطول (كل خيار حتى '.QuizAuthor::MAX_OPTION_CHARS.' حرف).')
                ->required(),
            'correct_option' => $schema->integer()
                ->description('ترتيب الإجابة الصحيحة في المصفوفة من 0 إلى 3.')
                ->required(),
            'explanation' => $schema->string()
                ->description('جملة أو جملتان تشرحان سبب صحة الإجابة (حتى '.QuizAuthor::MAX_EXPLANATION_CHARS.' حرف).')
                ->required(),
            'hint' => $schema->string()
                ->description('تلميح خفيف يوجّه دون كشف (حتى '.QuizAuthor::MAX_HINT_CHARS.' حرف).')
                ->required(),
            'obvious_hint' => $schema->string()
                ->description('تلميح أوضح يكاد يكشف الإجابة (حتى '.QuizAuthor::MAX_HINT_CHARS.' حرف).')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $question = trim($request->string('question')->toString());
        $body = trim($request->string('body')->toString());
        $options = array_values(array_map(
            fn (mixed $option): string => trim((string) $option),
            $request->array('options'),
        ));
        $correct = $request->integer('correct_option');
        $explanation = trim($request->string('explanation')->toString());
        $hint = trim($request->string('hint')->toString());
        $obviousHint = trim($request->string('obvious_hint')->toString());

        $problems = $this->validate($question, $body, $options, $correct);

        if ($problems !== []) {
            return "رُفض السؤال للمشاكل التالية:\n- ".implode("\n- ", $problems)
                ."\nصحّحها جميعاً ثم استدعِ الأداة مرة أخرى.";
        }

        $this->accepted = [
            'question' => $question,
            'body' => $body === '' ? null : $body,
            'options' => $options,
            'correct_option' => $correct,
            'explanation' => $explanation === '' ? null : Str::limit($explanation, QuizAuthor::MAX_EXPLANATION_CHARS, ''),
            'hint' => $hint === '' ? null : Str::limit($hint, QuizAuthor::MAX_HINT_CHARS, ''),
            'obvious_hint' => $obviousHint === '' ? null : Str::limit($obviousHint, QuizAuthor::MAX_HINT_CHARS, ''),
        ];

        return 'تم اعتماد السؤال بنجاح. توقف الآن ولا تُجرِ أي تعديل إضافي.';
    }

    /**
     * The accepted question payload, or null if the model never submitted a
     * valid one.
     *
     * @return array{question: string, body: string|null, options: array<int, string>, correct_option: int, explanation: string|null, hint: string|null, obvious_hint: string|null}|null
     */
    public function accepted(): ?array
    {
        return $this->accepted;
    }

    /**
     * Collect every problem with a candidate question at once, so the model can
     * fix them all in a single correction rather than one round-trip each.
     *
     * @param  array<int, string>  $options
     * @return array<int, string>
     */
    private function validate(string $question, string $body, array $options, int $correct): array
    {
        $problems = [];

        if ($question === '') {
            $problems[] = 'نص السؤال فارغ.';
        } elseif (mb_strlen($question) > QuizAuthor::MAX_QUESTION_CHARS) {
            $problems[] = 'السؤال أطول من '.QuizAuthor::MAX_QUESTION_CHARS.' حرف.';
        }

        if (mb_strlen($body) > QuizAuthor::MAX_BODY_CHARS) {
            $problems[] = 'المقدمة (body) أطول من '.QuizAuthor::MAX_BODY_CHARS.' حرف.';
        }

        if (count($options) !== 4) {
            $problems[] = 'يجب أن تكون الخيارات أربعة بالضبط.';

            return $problems;
        }

        if (in_array('', $options, true)) {
            $problems[] = 'أحد الخيارات فارغ.';
        }

        foreach ($options as $option) {
            if (mb_strlen($option) > QuizAuthor::MAX_OPTION_CHARS) {
                $problems[] = 'أحد الخيارات أطول من '.QuizAuthor::MAX_OPTION_CHARS.' حرف.';
                break;
            }
        }

        if (count(array_unique($options)) !== 4) {
            $problems[] = 'الخيارات متكررة — اجعلها أربعة مختلفة.';
        }

        if ($correct < 0 || $correct > 3) {
            $problems[] = 'ترتيب الإجابة الصحيحة يجب أن يكون بين 0 و3.';

            return $problems;
        }

        if ($this->correctOptionLengthLead($options, $correct) > QuizAuthor::MAX_CORRECT_OPTION_LENGTH_LEAD) {
            $problems[] = 'الإجابة الصحيحة أطول بوضوح من بقية الخيارات (تلميح يكشفها) — أعد صياغة الخيارات الأربعة لتكون متقاربة الطول.';
        }

        return $problems;
    }

    /**
     * How many characters longer the correct option runs than the average of
     * the three distractors.
     *
     * @param  array<int, string>  $options
     */
    private function correctOptionLengthLead(array $options, int $correct): float
    {
        $distractorLengths = [];

        foreach ($options as $index => $option) {
            if ($index !== $correct) {
                $distractorLengths[] = mb_strlen($option);
            }
        }

        return mb_strlen($options[$correct]) - (array_sum($distractorLengths) / count($distractorLengths));
    }
}
