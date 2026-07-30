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
     * Punctuation that the bidi algorithm reorders when it sits at the edge of
     * a Latin token inside an Arabic sentence. Brackets, parentheses and quotes
     * are deliberately absent — they mirror correctly around an LTR run, which
     * is what makes the house style «المكدس (Stack)» safe.
     */
    private const BIDI_RISKY_EDGE = '-_.+*/\\=<>|:;#$%&~^@';

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

        $problems = $this->validate($question, $body, $options, $correct, $explanation, $hint, $obviousHint);

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
    private function validate(
        string $question,
        string $body,
        array $options,
        int $correct,
        string $explanation,
        string $hint,
        string $obviousHint,
    ): array {
        $problems = [];

        if ($question === '') {
            $problems[] = 'نص السؤال فارغ.';
        } elseif (mb_strlen($question) > QuizAuthor::MAX_QUESTION_CHARS) {
            $problems[] = 'السؤال أطول من '.QuizAuthor::MAX_QUESTION_CHARS.' حرف.';
        }

        $unformatted = [
            'السؤال' => $question,
            'الشرح' => $explanation,
            'التلميح الأول' => $hint,
            'التلميح الثاني' => $obviousHint,
        ];

        foreach ($options as $index => $option) {
            $unformatted['الخيار '.($index + 1)] = $option;
        }

        $problems = array_merge($problems, $this->unformattedTextProblems($unformatted));

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
     * Everything except `body` reaches Telegram as unformatted text — the poll
     * carries the question, options and explanation with no parse_mode, and the
     * reminders send the hints escaped rather than rendered. So markdown there
     * shows its own markers, and a Latin code token there cannot be wrapped in
     * a directional isolate the way {@see \App\Services\TelegramMarkdownService}
     * wraps `body`. Both belong in `body` instead, which is a real formatted
     * message.
     *
     * @param  array<string, string>  $fields  label => text
     * @return array<int, string>
     */
    private function unformattedTextProblems(array $fields): array
    {
        $problems = [];

        foreach ($fields as $label => $text) {
            if (str_contains($text, '`')) {
                $problems[] = $label.' يحتوي علامات ماركداون (`) — وهو يُرسل نصاً عادياً فتظهر العلامات نفسها للقارئ. انقل الكود إلى body داخل سياج ``` ولا تكتب علامات تنسيق هنا.';
            }

            $risky = $this->bidiRiskyTokens($text);

            if ($risky !== []) {
                $problems[] = $label.' يحتوي رموزاً يفسد ترتيبها داخل النص العربي ('.implode('، ', $risky).') — الشرطات والنقاط على أطراف الرمز تنتقل إلى الجهة الخطأ فيقرأها الطالب مقلوبة. انقل هذه الرموز إلى body داخل سياج ``` واكتب هنا إشارةً إليها بالكلام.';
            }
        }

        return $problems;
    }

    /**
     * Tokens whose rendering the bidi algorithm breaks: a Latin/digit token
     * carrying edge punctuation, such as `-rwxr-xr--` or `--global`. Interior
     * punctuation is safe (`Big-O`, `node.js` sit between two strong Latin
     * characters), and trailing Arabic sentence punctuation is stripped before
     * the check so an ordinary full stop is never mistaken for part of a token.
     *
     * @return array<int, string>
     */
    private function bidiRiskyTokens(string $text): array
    {
        $risky = [];
        $edges = preg_quote(self::BIDI_RISKY_EDGE, '/');

        foreach (preg_split('/\s+/u', $text) ?: [] as $token) {
            $core = ltrim($token, '([{«"\'');
            $core = rtrim($core, '،؛؟!.,)]}»"\'');

            if ($core === '' || preg_match('/[a-zA-Z0-9]/', $core) !== 1) {
                continue;
            }

            if (preg_match('/^['.$edges.']|['.$edges.']$/u', $core) === 1) {
                $risky[] = $core;
            }
        }

        return array_values(array_unique($risky));
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
