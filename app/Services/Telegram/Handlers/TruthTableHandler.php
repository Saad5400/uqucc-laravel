<?php

namespace App\Services\Telegram\Handlers;

use App\Services\Logic\TruthTableGenerator;
use InvalidArgumentException;
use Telegram\Bot\Api;
use Telegram\Bot\Objects\Message;

class TruthTableHandler extends BaseHandler
{
    public function __construct(Api $telegram, private readonly TruthTableGenerator $generator)
    {
        parent::__construct($telegram);
    }

    public function handle(Message $message): void
    {
        $text = $message->getText();
        $content = is_string($text) ? trim($text) : '';

        if (! preg_match('/^\/truth(?:@[\w_]+)?\s+(.+)/us', $content, $matches)) {
            return;
        }

        $expression = trim($matches[1] ?? '');
        if ($expression === '') {
            $this->reply($message, '⚠️ الرجاء إدخال صيغة منطقية بعد الأمر.');
            return;
        }

        try {
            $result = $this->generator->generate($expression);
            $table = $this->renderTable($result);

            $this->replyMarkdown($message, "```\n{$table}\n```");
        } catch (InvalidArgumentException $e) {
            $this->reply($message, '⚠️ '.$e->getMessage());
        } catch (\Throwable $e) {
            $this->reply($message, '❌ حدث خطأ أثناء إنشاء جدول الحقيقة.');
        }
    }

    /**
     * @param array{variables: string[], columns: array<int, array{label: string, node: array}>, rows: array<int, array<string, bool>>, normalized: string} $result
     */
    private function renderTable(array $result): string
    {
        $columns = array_map(fn ($column) => $column['label'], $result['columns']);
        $widths = [];

        foreach ($columns as $column) {
            $widths[$column] = mb_strlen($column);
        }

        foreach ($result['rows'] as $row) {
            foreach ($columns as $column) {
                $widths[$column] = max($widths[$column], 1);
            }
        }

        $renderLine = function (array $values) use ($columns, $widths): string {
            $parts = [];
            foreach ($columns as $column) {
                $parts[] = $this->padText($values[$column], $widths[$column]);
            }

            return implode(' | ', $parts);
        };

        $lines = [
            'Expression: '.$result['normalized'],
            $renderLine(array_combine($columns, $columns)),
            implode('-+-', array_map(fn ($column) => str_repeat('-', $widths[$column]), $columns)),
        ];

        foreach ($result['rows'] as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[$column] = $row[$column] ? 'T' : 'F';
            }
            $lines[] = $renderLine($values);
        }

        return implode("\n", $lines);
    }

    private function padText(string $text, int $width): string
    {
        $length = mb_strlen($text);
        if ($length >= $width) {
            return $text;
        }

        $total = $width - $length;
        $left = intdiv($total, 2);
        $right = $total - $left;

        return str_repeat(' ', $left).$text.str_repeat(' ', $right);
    }
}
