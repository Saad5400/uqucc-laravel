<?php

namespace App\Ai\OpinionPoll;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The single tool behind opinion-poll generation. The model calls it with a
 * candidate poll; it validates what can be validated mechanically — plain
 * text, the option count, the length caps, no numbering the poll UI already
 * draws — and returns every problem at once so the model corrects them inside
 * the same conversation, with its own attempt still in context, rather than
 * through a blind stateless retry.
 *
 * What it cannot check is the rule that matters most: that the question has no
 * right answer. That one lives in the prompt, and an admin still reviews the
 * queue before anything goes out.
 */
class SubmitOpinionPollTool implements Tool
{
    /**
     * The last accepted poll, or null until one passes validation.
     *
     * @var array{question: string, options: array<int, string>}|null
     */
    private ?array $accepted = null;

    public function name(): string
    {
        return 'submit_opinion_poll';
    }

    public function description(): Stringable|string
    {
        return 'Submit the proposed opinion poll for validation and acceptance. '
            .'The question and the options are plain text sent straight to Telegram. '
            .'Call this tool with every required field filled in. If it replies with a list of problems, '
            .'fix all of them and call the tool again until the poll is accepted. '
            .'Never write the poll out as plain text instead of calling this tool.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()
                ->description('The poll question in Arabic, plain text (no HTML, no markdown), up to '
                    .OpinionPollAuthor::MAX_QUESTION_CHARS.' characters. It must be a question about the reader '
                    .'themselves — their habit, tool or preference — with no correct answer.')
                ->required(),
            'options' => $schema->array()->items($schema->string())
                ->min(OpinionPollAuthor::MIN_OPTIONS)
                ->max(OpinionPollAuthor::MAX_OPTIONS)
                ->description('Between '.OpinionPollAuthor::MIN_OPTIONS.' and '.OpinionPollAuthor::MAX_OPTIONS
                    .' distinct plain-text answers in Arabic, each up to '.OpinionPollAuthor::MAX_OPTION_CHARS
                    .' characters. Telegram numbers them itself, so never prefix them. Every option must be one a '
                    .'real member could honestly pick.')
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $question = trim($request->string('question')->toString());
        $options = array_values(array_map(
            fn (mixed $option): string => trim((string) $option),
            $request->array('options'),
        ));

        $problems = $this->validate($question, $options);

        if ($problems !== []) {
            return "رُفض الاستطلاع للمشاكل التالية:\n- ".implode("\n- ", $problems)
                ."\nصحّحها جميعاً ثم استدعِ الأداة مرة أخرى.";
        }

        $this->accepted = [
            'question' => $question,
            'options' => $options,
        ];

        return 'تم اعتماد الاستطلاع بنجاح. توقف الآن ولا تُجرِ أي تعديل إضافي.';
    }

    /**
     * The accepted poll payload, or null if the model never submitted a valid
     * one.
     *
     * @return array{question: string, options: array<int, string>}|null
     */
    public function accepted(): ?array
    {
        return $this->accepted;
    }

    /**
     * Collect every problem with a candidate poll at once, so the model can fix
     * them all in a single correction rather than one round-trip each.
     *
     * @param  array<int, string>  $options
     * @return array<int, string>
     */
    private function validate(string $question, array $options): array
    {
        $problems = [];

        if ($question === '') {
            $problems[] = 'نص الاستطلاع فارغ.';
        } elseif (mb_strlen($question) > OpinionPollAuthor::MAX_QUESTION_CHARS) {
            $problems[] = 'نص الاستطلاع أطول من '.OpinionPollAuthor::MAX_QUESTION_CHARS.' حرف.';
        }

        if ($this->looksLikeMarkup($question)) {
            $problems[] = 'نص الاستطلاع يحتوي وسوماً أو تنسيقاً — تيليجرام يعرضه نصاً عادياً، فاكتبه بلا أي وسوم.';
        }

        $count = count($options);

        if ($count < OpinionPollAuthor::MIN_OPTIONS || $count > OpinionPollAuthor::MAX_OPTIONS) {
            $problems[] = 'عدد الخيارات يجب أن يكون بين '.OpinionPollAuthor::MIN_OPTIONS
                .' و'.OpinionPollAuthor::MAX_OPTIONS.' — أرسلت '.$count.'.';

            return $problems;
        }

        if (in_array('', $options, true)) {
            $problems[] = 'أحد الخيارات فارغ.';
        }

        foreach ($options as $option) {
            if (mb_strlen($option) > OpinionPollAuthor::MAX_OPTION_CHARS) {
                $problems[] = 'أحد الخيارات أطول من '.OpinionPollAuthor::MAX_OPTION_CHARS
                    .' حرف — الخيار القصير يُقرأ بلمحة، فاختصرها كلها.';
                break;
            }
        }

        foreach ($options as $option) {
            if ($this->isNumbered($option)) {
                $problems[] = 'أحد الخيارات مسبوق برقم أو حرف ترتيب — تيليجرام يرقّم الخيارات بنفسه، فاحذف الترقيم.';
                break;
            }
        }

        if ($this->looksLikeMarkup(implode(' ', $options))) {
            $problems[] = 'أحد الخيارات يحتوي وسوماً أو تنسيقاً — اكتب الخيارات نصاً عادياً.';
        }

        if (count(array_unique($options)) !== $count) {
            $problems[] = 'الخيارات متكررة — اجعلها كلها مختلفة.';
        }

        if (in_array($question, $options, true)) {
            $problems[] = 'أحد الخيارات هو نص السؤال نفسه.';
        }

        return $problems;
    }

    /** HTML or markdown emphasis a plain-text Telegram poll would show literally. */
    private function looksLikeMarkup(string $text): bool
    {
        return preg_match('/<[a-z\/!][^>]*>|\*\*|__|`/i', $text) === 1;
    }

    /** «1.» / «2)» / «أ-» and friends — numbering the poll UI already draws. */
    private function isNumbered(string $option): bool
    {
        return preg_match('/^\s*(\d+|[\x{0621}-\x{064A}])\s*[.)\-–]\s+/u', $option) === 1;
    }
}
