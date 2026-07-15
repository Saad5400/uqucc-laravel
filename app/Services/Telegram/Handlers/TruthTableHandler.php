<?php

namespace App\Services\Telegram\Handlers;

use App\Services\Logic\FormulaError;
use App\Services\Logic\TruthTable;
use App\Services\Logic\TruthTableGenerator;
use App\Services\Logic\TruthTableImageRenderer;
use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Objects\Message;

/**
 * «جدول الصواب [صيغة]» — replies with the truth table of a propositional
 * formula as a PNG (a `<pre>` text table wraps and breaks on mobile
 * Telegram), using the same engine as the site's truth table tool and the AI
 * assistant ({@see TruthTableGenerator}).
 */
class TruthTableHandler extends BaseHandler
{
    /**
     * «جدول الصواب p and q» (also the جدول الصدق/جدول الحقيقة phrasings), or
     * /truthtable | /truth for Latin-keyboard users.
     */
    protected const COMMAND_PATTERN = '/^(?:جدول\s+(?:الصواب|الصدق|الحقيقة)|\/truth(?:table)?)\s+(.+)$/u';

    /**
     * Past this many rows (> 6 variables) the PNG would exceed Telegram's
     * photo dimension limits, so we point to the web tool instead.
     */
    protected const MAX_IMAGE_ROWS = 64;

    public function __construct(
        Api $telegram,
        private readonly TruthTableGenerator $generator,
        private readonly TruthTableImageRenderer $imageRenderer,
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

        $this->trackCommand($message, 'truth_table');

        try {
            $table = $this->generator->generate(trim($matches[1]));
        } catch (FormulaError $error) {
            $this->reply($message, $error->getMessage());

            return;
        }

        if (count($table->rows) > self::MAX_IMAGE_ROWS) {
            $this->replyHtml(
                $message,
                'الجدول أكبر من أن يُعرض هنا — جرّبه في أداة جدول الصواب على الموقع: '
                .$this->escapeHtml(route('tools.truth-table')),
            );

            return;
        }

        $caption = $this->escapeHtml("الصيغة: {$table->formula}\n{$table->verdict()}");

        try {
            $this->replyPhoto(
                $message,
                InputFile::createFromContents($this->imageRenderer->render($table), 'truth-table.png'),
                $caption,
            );
        } catch (\Exception) {
            $this->replyWithTextTable($message, $table);
        }
    }

    /**
     * Fallback when the image cannot be rendered or sent: the monospace text
     * table in a `<pre>` block.
     */
    protected function replyWithTextTable(Message $message, TruthTable $table): void
    {
        $this->replyHtml($message, implode("\n", [
            'الصيغة: <code>'.$this->escapeHtml($table->formula).'</code>',
            '<pre>'.$this->escapeHtml($table->toTextTable()).'</pre>',
            $this->escapeHtml($table->verdict()),
        ]));
    }
}
