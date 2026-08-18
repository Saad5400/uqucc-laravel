<?php

namespace Tests\Support;

/**
 * Builds OpenRouter chat-completion SSE bodies for Http::fake, matching the
 * frame shapes ai-kit's gateway parses. Used where a REAL generation loop
 * must run (the Approvable pause/resume path executes tools only against a
 * non-faked gateway), so the HTTP layer is the only thing faked.
 */
class OpenRouterSse
{
    /**
     * @param  array<int, array<string, mixed>>  $frames
     */
    public static function body(array $frames): string
    {
        $lines = array_map(
            fn (array $frame): string => 'data: '.json_encode($frame, JSON_UNESCAPED_UNICODE)."\n\n",
            $frames,
        );

        return implode('', $lines)."data: [DONE]\n\n";
    }

    /**
     * A streamed body that calls ONE tool with the given JSON arguments.
     */
    public static function toolCallBody(string $callId, string $tool, array $arguments): string
    {
        return static::body([
            static::chunk(['tool_calls' => [[
                'index' => 0,
                'id' => $callId,
                'function' => ['name' => $tool, 'arguments' => json_encode($arguments, JSON_UNESCAPED_UNICODE)],
            ]]], finishReason: 'tool_calls'),
            static::usageFrame(['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.001]),
        ]);
    }

    /**
     * A streamed body answering with plain text.
     */
    public static function textBody(string $text): string
    {
        return static::body([
            static::chunk(['content' => $text], finishReason: 'stop'),
            static::usageFrame(['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.001]),
        ]);
    }

    /**
     * A streamed body that thinks aloud before answering — the shape the
     * kit's ReasoningOpenRouterGateway turns into `reasoning` wire events.
     */
    public static function reasoningTextBody(string $reasoning, string $text): string
    {
        return static::body([
            static::reasoningFrame($reasoning),
            static::chunk(['content' => $text], finishReason: 'stop'),
            static::usageFrame(['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.001]),
        ]);
    }

    /**
     * One thinking delta. OpenRouter puts it on `delta.reasoning`; the
     * DeepSeek-native spelling `delta.reasoning_content` is parsed too, so
     * pass `field:` to exercise that side.
     *
     * @return array<string, mixed>
     */
    public static function reasoningFrame(string $text, string $field = 'reasoning', string $id = 'gen-test-1'): array
    {
        return static::chunk([$field => $text], id: $id);
    }

    /**
     * @param  array<string, mixed>  $delta
     * @return array<string, mixed>
     */
    public static function chunk(array $delta, ?string $finishReason = null, string $id = 'gen-test-1'): array
    {
        $choice = ['delta' => $delta];

        if ($finishReason !== null) {
            $choice['finish_reason'] = $finishReason;
        }

        return ['id' => $id, 'model' => 'test/model', 'choices' => [$choice]];
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    public static function usageFrame(array $usage, string $id = 'gen-test-1'): array
    {
        return ['id' => $id, 'model' => 'test/model', 'usage' => $usage];
    }
}
