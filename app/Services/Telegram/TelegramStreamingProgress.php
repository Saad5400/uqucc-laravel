<?php

namespace App\Services\Telegram;

use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Laravel\Ai\Streaming\Events\ToolResult;
use Telegram\Bot\Api;
use Throwable;

/**
 * Live progress for a streamed assistant turn: keeps editing the placeholder
 * message while the model works — the reasoning tail and tool activity first,
 * then the growing answer text. Gone once the caller sends the final reply.
 *
 * Robustness rules, all Telegram-imposed:
 *  - Progress edits are PLAIN TEXT (no parse_mode), so a partial render can
 *    never be rejected as invalid markup mid-stream.
 *  - Edits are throttled to one per {@see self::EDIT_INTERVAL_SECONDS} and
 *    back off further on failure, honouring flood-control "retry after N".
 *  - Every render is hard-capped under Telegram's 4096-char message limit,
 *    showing the TAIL of a long answer.
 *  - An edit failure never fails the turn — the next tick simply retries.
 */
class TelegramStreamingProgress
{
    /** Telegram's hard per-message character limit. */
    private const TELEGRAM_MESSAGE_LIMIT = 4096;

    /**
     * Minimum seconds between progress edits: 15 edits/min stays clear of
     * Telegram's per-chat limits even in groups (20 messages/min).
     */
    private const EDIT_INTERVAL_SECONDS = 4.0;

    /** Extra hold-off after a failed edit (network error, flood control). */
    private const FAILURE_BACKOFF_SECONDS = 10.0;

    /** How much of the reasoning stream to show, from its end. */
    private const REASONING_TAIL_CHARS = 160;

    private const MAX_TOOL_LINES = 5;

    /** Answer-tail budget, leaving headroom for the header and tool lines. */
    private const TEXT_TAIL_CHARS = 3500;

    private string $reasoning = '';

    private string $text = '';

    /** @var array<string, string> Tool activity lines keyed by tool-call id. */
    private array $toolLines = [];

    private string $lastRender = '';

    private float $nextEditAt = 0.0;

    public function __construct(
        private readonly Api $telegram,
        private readonly int $chatId,
        private readonly int $messageId,
    ) {}

    /**
     * Fold one stream event into the progress state and, when the throttle
     * window allows, reflect it on the placeholder message.
     */
    public function note(StreamEvent $event): void
    {
        if ($event instanceof ReasoningDelta) {
            $this->reasoning .= $event->delta;
        } elseif ($event instanceof ToolCall) {
            $this->toolLines[$event->toolCall->id] = $this->describeTool($event->toolCall->name, $event->toolCall->arguments).' …';
        } elseif ($event instanceof ToolResult) {
            $this->toolLines[$event->toolResult->id] = $this->describeTool($event->toolResult->name, $event->toolResult->arguments).' ✓';
        } elseif ($event instanceof TextDelta) {
            $this->text .= $event->delta;
        } else {
            return;
        }

        $this->editThrottled();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function describeTool(string $name, array $arguments): string
    {
        return match ($name) {
            'search_content' => '🔎 يبحث'.$this->argumentDetail($arguments['query'] ?? null),
            'get_page' => '📄 يقرأ صفحة'.$this->argumentDetail($arguments['slug'] ?? null),
            'list_stale_pages' => '🗂 يراجع تواريخ الصفحات',
            'calculate_gpa' => '🧮 يحسب المعدل',
            'calculate_deprivation' => '🧮 يحسب نسبة الغياب والحرمان',
            'calculate_transfer' => '🧮 يحسب مفاضلة التحويل',
            'find_tutors' => '🎓 يبحث عن مدرّسين',
            default => '⚙️ '.$name,
        };
    }

    private function argumentDetail(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        return ': «'.mb_substr(trim($value), 0, 60).'»';
    }

    /**
     * The snapshot shown mid-turn: thinking header (with the reasoning tail)
     * until the answer starts, the latest tool activity, then the answer so
     * far with a typing cursor.
     */
    private function render(): string
    {
        $sections = [];

        if ($this->text === '') {
            $header = '⏳ جاري التفكير…';
            $reasoningTail = $this->reasoningTail();

            if ($reasoningTail !== '') {
                $header .= "\n🧠 ".$reasoningTail;
            }

            $sections[] = $header;
        }

        if ($this->toolLines !== []) {
            $sections[] = implode("\n", array_slice(array_values($this->toolLines), -self::MAX_TOOL_LINES));
        }

        if ($this->text !== '') {
            $sections[] = $this->textTail().' ▌';
        }

        $render = implode("\n\n", $sections);

        return mb_strlen($render) > self::TELEGRAM_MESSAGE_LIMIT
            ? mb_substr($render, 0, self::TELEGRAM_MESSAGE_LIMIT)
            : $render;
    }

    private function reasoningTail(): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $this->reasoning));

        if ($flat === '') {
            return '';
        }

        return mb_strlen($flat) > self::REASONING_TAIL_CHARS
            ? '…'.mb_substr($flat, -self::REASONING_TAIL_CHARS)
            : $flat;
    }

    private function textTail(): string
    {
        $text = trim($this->text);

        return mb_strlen($text) > self::TEXT_TAIL_CHARS
            ? '…'.mb_substr($text, -self::TEXT_TAIL_CHARS)
            : $text;
    }

    private function editThrottled(): void
    {
        if (microtime(true) < $this->nextEditAt) {
            return;
        }

        $render = $this->render();

        if ($render === '' || $render === $this->lastRender) {
            return;
        }

        try {
            $this->telegram->editMessageText([
                'chat_id' => $this->chatId,
                'message_id' => $this->messageId,
                'text' => $render,
            ]);

            $this->lastRender = $render;
            $this->nextEditAt = microtime(true) + self::EDIT_INTERVAL_SECONDS;
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), 'message is not modified')) {
                $this->lastRender = $render;
                $this->nextEditAt = microtime(true) + self::EDIT_INTERVAL_SECONDS;

                return;
            }

            $this->nextEditAt = microtime(true) + $this->retryDelay($exception);
        }
    }

    /**
     * Honour Telegram flood control's "Too Many Requests: retry after N"
     * hint; any other failure waits the flat backoff.
     */
    private function retryDelay(Throwable $exception): float
    {
        if (preg_match('/retry after (\d+)/i', $exception->getMessage(), $matches) === 1) {
            return max((float) $matches[1], self::FAILURE_BACKOFF_SECONDS);
        }

        return self::FAILURE_BACKOFF_SECONDS;
    }
}
