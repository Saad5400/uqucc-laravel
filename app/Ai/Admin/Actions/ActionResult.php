<?php

namespace App\Ai\Admin\Actions;

/**
 * The outcome of running an {@see AdminAction}. For a read the `message` holds
 * the rendered content the model reads; for a write it holds the human
 * confirmation line. Optional structured `data` lets a surface render richer
 * output without re-querying (unused by the text surfaces today).
 */
final class ActionResult
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public readonly string $message,
        public readonly ?array $data = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function text(string $message, ?array $data = null): self
    {
        return new self($message, $data);
    }
}
