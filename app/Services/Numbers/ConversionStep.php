<?php

namespace App\Services\Numbers;

/**
 * One worked step of a base conversion: an Arabic heading, an optional
 * sentence explaining the rule being applied, the machine lines that show the
 * arithmetic, and the line the step concludes with.
 *
 * The split matters to every surface that draws a step: `title`/`note`/
 * `result` are Arabic prose and flow right-to-left, while `lines` are machine
 * text (digits, ×, ÷, =) that must be laid out left-to-right in a monospace
 * face — see docs/ux-principles.md on LTR islands.
 */
final readonly class ConversionStep
{
    /**
     * @param  string  $title  Arabic heading, without a step number — the
     *                         surfaces number the steps themselves.
     * @param  list<string>  $lines  LTR machine lines, one per row of working.
     * @param  string|null  $note  Arabic sentence naming the rule, or null.
     * @param  string|null  $result  Arabic conclusion line, or null.
     */
    public function __construct(
        public string $title,
        public array $lines,
        public ?string $note = null,
        public ?string $result = null,
    ) {}

    /**
     * @return array{title: string, lines: list<string>, note: string|null, result: string|null}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'lines' => $this->lines,
            'note' => $this->note,
            'result' => $this->result,
        ];
    }
}
