<?php

namespace App\Ai\Quiz;

use App\Support\QuizContentHtml;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The single tool behind daily-question generation. The model calls it with a
 * candidate question; it sanitizes the authored HTML and validates the quality
 * rules (four unique options, a valid answer index, balanced option lengths,
 * the length caps). On any problem it returns the full list so the model —
 * still in the same agentic conversation, with its own previous attempt in
 * context — corrects everything and calls again, instead of a blind stateless
 * retry. On success it captures the normalized payload, which {@see QuizAuthor}
 * reads after the run via {@see accepted()}.
 *
 * The whole question — preamble, code and the four options — is rendered to an
 * image, and the Telegram poll shows only generic 1–4 choices, so there is no
 * bidi mangling to guard against here: the authored HTML carries its own
 * direction and the options are plain text drawn in the picture.
 */
class SubmitQuizQuestionTool implements Tool
{
    /**
     * The last accepted question, or null until one passes validation.
     *
     * @var array{question: string, options: array<int, string>, correct_option: int, explanation: string|null, hint: string|null, obvious_hint: string|null}|null
     */
    private ?array $accepted = null;

    public function name(): string
    {
        return 'submit_quiz_question';
    }

    public function description(): Stringable|string
    {
        return 'Submit the proposed question of the day for validation and acceptance. '
            .'The question field is a small HTML fragment (rendered to an image); the options are plain text. '
            .'Call this tool with every required field filled in. If it replies with a list of problems, '
            .'fix all of them and call the tool again until the question is accepted. '
            .'Never write the question out as plain text instead of calling this tool.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()
                ->description('The question as a small HTML fragment in Arabic: <p dir="rtl"> paragraphs for the teaching '
                    .'preamble and the question itself, and <pre dir="ltr"><code> for any code, each on its own line — never '
                    .'mixing Arabic and Latin in one line. Allowed tags: p, br, pre, code, strong, b, em, i, span, ul, ol, li, '
                    .'h3, h4; the only kept attribute is dir. Up to '.QuizAuthor::MAX_QUESTION_CHARS.' characters of text.')
                ->required(),
            'options' => $schema->array()->items($schema->string())->min(4)->max(4)
                ->description('Exactly four distinct plain-text answer options in Arabic, similar in length (each up to '
                    .QuizAuthor::MAX_OPTION_CHARS.' characters). They are drawn in the image labelled 1–4.')
                ->required(),
            'correct_option' => $schema->integer()
                ->description('Zero-based index of the correct answer within options, 0 to 3.')
                ->required(),
            'explanation' => $schema->string()
                ->description('One or two sentences in Arabic explaining why that answer is correct, shown after answering '
                    .'(up to '.QuizAuthor::MAX_EXPLANATION_CHARS.' characters).')
                ->required(),
            'hint' => $schema->string()
                ->description('A light hint in Arabic (plain text) that points the reader in the right direction without '
                    .'revealing the answer (up to '.QuizAuthor::MAX_HINT_CHARS.' characters).')
                ->required(),
            'obvious_hint' => $schema->string()
                ->description('A more obvious hint in Arabic (plain text) that almost gives the answer away (up to '
                    .QuizAuthor::MAX_HINT_CHARS.' characters).')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $question = QuizContentHtml::sanitize($request->string('question')->toString());
        $options = array_values(array_map(
            fn (mixed $option): string => trim((string) $option),
            $request->array('options'),
        ));
        $correct = $request->integer('correct_option');
        $explanation = trim($request->string('explanation')->toString());
        $hint = trim($request->string('hint')->toString());
        $obviousHint = trim($request->string('obvious_hint')->toString());

        $problems = $this->validate($question, $options, $correct, $explanation, $hint, $obviousHint);

        if ($problems !== []) {
            return "رُفض السؤال للمشاكل التالية:\n- ".implode("\n- ", $problems)
                ."\nصحّحها جميعاً ثم استدعِ الأداة مرة أخرى.";
        }

        $this->accepted = [
            'question' => $question,
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
     * @return array{question: string, options: array<int, string>, correct_option: int, explanation: string|null, hint: string|null, obvious_hint: string|null}|null
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
    private function validate(
        string $question,
        array $options,
        int $correct,
        string $explanation,
        string $hint,
        string $obviousHint,
    ): array {
        $problems = [];

        $questionLength = QuizContentHtml::textLength($question);

        if ($questionLength === 0) {
            $problems[] = 'نص السؤال فارغ.';
        } elseif ($questionLength > QuizAuthor::MAX_QUESTION_CHARS) {
            $problems[] = 'نص السؤال أطول من '.QuizAuthor::MAX_QUESTION_CHARS.' حرف.';
        }

        if (mb_strlen($explanation) > QuizAuthor::MAX_EXPLANATION_CHARS) {
            $problems[] = 'الشرح أطول من '.QuizAuthor::MAX_EXPLANATION_CHARS.' حرف.';
        }

        foreach (['التلميح الأول' => $hint, 'التلميح الثاني' => $obviousHint] as $label => $text) {
            if (mb_strlen($text) > QuizAuthor::MAX_HINT_CHARS) {
                $problems[] = $label.' أطول من '.QuizAuthor::MAX_HINT_CHARS.' حرف.';
            }
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
