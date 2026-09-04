<?php

namespace App\Services\Telegram\Handlers;

use App\Helpers\ArabicNormalizer;
use App\Services\Numbers\BaseConversion;
use App\Services\Numbers\BaseConversionError;
use App\Services\Numbers\BaseConversionImageRenderer;
use App\Services\Numbers\BaseConverter;
use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Objects\Message;

/**
 * «حول 2AF من 16 إلى 2» — replies with the conversion and every step behind
 * it as a PNG card, using the same engine as the site's converter tool
 * ({@see BaseConverter}). The working is Arabic headings wrapped around LTR
 * arithmetic, which a `<pre>` block on mobile Telegram shreds; the card is
 * drawn with the two directions kept apart.
 *
 * «حول» is also an ordinary Arabic word, so the Arabic triggers answer only a
 * message that parses all the way down to a number and two bases — «حول
 * الجامعة من الشمال إلى الجنوب» is somebody talking, not a command, and gets
 * silence. The slash commands are unambiguous, so they answer with usage when
 * their arguments do not parse.
 */
class BaseConversionHandler extends BaseHandler
{
    /**
     * «حول/حوّل/تحويل [الباقي]» or /base | /convert for Latin-keyboard users.
     */
    protected const COMMAND_PATTERN = '/^(?<command>حوّل|حول|تحويل|\/base|\/convert)(?:\s+(?<arguments>.+))?$/u';

    /**
     * «[عدد] من [أساس] إلى [أساس]» — the bases are matched loosely because
     * they may be a number or a name of one or two words («ست عشري»).
     */
    protected const ARGUMENTS_PATTERN = '/^(?<number>\S+)\s+من\s+(?<from>.+?)\s+(?:إلى|الى)\s+(?<to>.+)$/u';

    /** «[عدد] [أساس] [أساس]» — the terse form, e.g. «/base 2AF 16 2». */
    protected const TERSE_ARGUMENTS_PATTERN = '/^(?<number>\S+)\s+(?<from>\S+)\s+(?<to>\S+)$/u';

    /** A token that is plausibly a number in some base, rather than a word. */
    protected const NUMBER_PATTERN = '/^[+\-−]?[0-9a-z]+(?:[.,٫][0-9a-z]+)?$/i';

    /**
     * Base names students write instead of the number. Keys are
     * {@see ArabicNormalizer::normalize()} output, so hamza, alef maqsura and
     * the Arabic-Indic digits are already folded.
     *
     * @var array<string, int>
     */
    protected const BASE_NAMES = [
        'ثنايي' => 2,
        'binary' => 2,
        'bin' => 2,
        'ثماني' => 8,
        'octal' => 8,
        'oct' => 8,
        'عشري' => 10,
        'decimal' => 10,
        'dec' => 10,
        'ست عشري' => 16,
        'ستعشري' => 16,
        'سادس عشر' => 16,
        'سداسي عشر' => 16,
        'hex' => 16,
        'hexadecimal' => 16,
    ];

    /**
     * Past this many lines of working the card would exceed Telegram's photo
     * dimension limits, so we point at the web tool instead.
     */
    protected const MAX_IMAGE_LINES = 40;

    public function __construct(
        Api $telegram,
        private readonly BaseConverter $converter,
        private readonly BaseConversionImageRenderer $imageRenderer,
    ) {
        parent::__construct($telegram);
    }

    public function handle(Message $message): void
    {
        $text = $message->getText();
        $content = is_string($text) ? trim($text) : '';

        if (preg_match(self::COMMAND_PATTERN, $content, $matches) !== 1) {
            return;
        }

        $isSlashCommand = str_starts_with($matches['command'], '/');
        $request = $this->parseArguments(trim($matches['arguments'] ?? ''));

        if ($request === null) {
            if ($isSlashCommand) {
                $this->trackCommand($message, 'base_conversion');
                $this->replyHtml($message, $this->usage());
            }

            return;
        }

        [$number, $fromBase, $toBase] = $request;

        $this->trackCommand($message, 'base_conversion');

        try {
            $conversion = $this->converter->convert($number, $fromBase, $toBase);
        } catch (BaseConversionError $error) {
            $this->reply($message, $error->getMessage());

            return;
        }

        if ($conversion->lineCount() > self::MAX_IMAGE_LINES) {
            $this->replyHtml(
                $message,
                'خطوات الحل أطول من أن تُعرض هنا — جرّبها في أداة تحويل الأعداد على الموقع: '
                .$this->escapeHtml(route('tools.base-converter')),
            );

            return;
        }

        try {
            $this->replyPhoto(
                $message,
                InputFile::createFromContents($this->imageRenderer->render($conversion), 'base-conversion.png'),
                $this->escapeHtml($conversion->summary()),
            );
        } catch (\Exception) {
            $this->replyWithTextSteps($message, $conversion);
        }
    }

    /**
     * Read the command's arguments into a number and two bases, or null when
     * the message is not a conversion request at all.
     *
     * @return array{0: string, 1: int, 2: int}|null
     */
    protected function parseArguments(string $arguments): ?array
    {
        if ($arguments === '') {
            return null;
        }

        if (preg_match(self::ARGUMENTS_PATTERN, $arguments, $matches) !== 1
            && preg_match(self::TERSE_ARGUMENTS_PATTERN, $arguments, $matches) !== 1) {
            return null;
        }

        // Folded first, so «٢٥٥» is recognised as a number here exactly as the
        // converter would read it.
        $number = ArabicNormalizer::normalizeDigits($matches['number']);

        if (preg_match(self::NUMBER_PATTERN, $number) !== 1) {
            return null;
        }

        $fromBase = $this->resolveBase($matches['from']);
        $toBase = $this->resolveBase($matches['to']);

        if ($fromBase === null || $toBase === null) {
            return null;
        }

        return [$number, $fromBase, $toBase];
    }

    /**
     * A base written as a number («16»، «١٦»، «الأساس 16») or as a name
     * («ست عشري»، «hex»), or null when it is neither.
     */
    protected function resolveBase(string $token): ?int
    {
        // The normalizer folds the definite article, hamza forms, alef
        // maqsura and the Arabic-Indic digits, which is every spelling the
        // table below would otherwise have to carry twice.
        $token = ArabicNormalizer::normalizeWithoutDefiniteArticle($token);
        $token = trim(preg_replace('/^(?:اساس|نظام)\s+/u', '', $token) ?? $token);

        if (ctype_digit($token)) {
            $base = (int) $token;

            return $base >= BaseConverter::MIN_BASE && $base <= BaseConverter::MAX_BASE ? $base : null;
        }

        return self::BASE_NAMES[$token] ?? null;
    }

    /**
     * How to write the command, shown when a slash command arrives with
     * arguments that do not parse.
     */
    protected function usage(): string
    {
        return implode("\n", [
            '<b>تحويل الأعداد بين الأنظمة</b>',
            '',
            'اكتب: <code>حول [العدد] من [أساس] إلى [أساس]</code>',
            'مثال: <code>حول 2AF من 16 إلى 2</code>',
            'أو باختصار: <code>/base 255 10 2</code>',
            '',
            'الأساس رقم بين 2 و 36، أو أحد الأسماء: ثنائي، ثماني، عشري، ست عشري.',
        ]);
    }

    /**
     * Fallback when the card cannot be rendered or sent: the same working as
     * monospace text.
     */
    protected function replyWithTextSteps(Message $message, BaseConversion $conversion): void
    {
        $this->replyHtml($message, '<pre>'.$this->escapeHtml($conversion->toText()).'</pre>');
    }
}
