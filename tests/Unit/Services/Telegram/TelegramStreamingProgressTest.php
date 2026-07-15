<?php

use App\Services\Telegram\TelegramStreamingProgress;
use Laravel\Ai\Responses\Data\ToolCall as ToolCallData;
use Laravel\Ai\Streaming\Events\ReasoningDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Tests\Fakes\FakeTelegramApi;

/** Bidi controls the progress render leans on to keep Arabic laid out RTL. */
const RTL_MARK = "\u{200F}";
const FIRST_STRONG_ISOLATE = "\u{2068}";
const POP_DIRECTIONAL_ISOLATE = "\u{2069}";

function progressFor(FakeTelegramApi $api): TelegramStreamingProgress
{
    return new TelegramStreamingProgress($api, 1_234, 5_678);
}

/**
 * The rendered snapshot of the last edit, unwrapped from its
 * expandable-blockquote transport and un-escaped so bidi assertions read the
 * render itself rather than the HTML envelope.
 */
function lastProgressText(FakeTelegramApi $api): string
{
    $text = (string) ($api->editedMessages[array_key_last($api->editedMessages)]['text'] ?? '');

    $inner = (string) preg_replace('#^<blockquote expandable>(.*)</blockquote>$#s', '$1', $text);

    return html_entity_decode($inner, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function toolCallEvent(string $name, array $arguments): ToolCallEvent
{
    return new ToolCallEvent('evt-1', new ToolCallData('call-1', $name, $arguments, 'call-1'), 0);
}

it('wraps a tool argument slug in a bidi isolate so LTR machine text cannot scramble the Arabic', function () {
    $api = new FakeTelegramApi;

    progressFor($api)->note(toolCallEvent('get_page', [
        'slug' => '/algamaa-oalagraaaat-alakadymy/alkbol-oalthoyl/altskyn',
    ]));

    $text = lastProgressText($api);

    expect($text)
        ->toContain(FIRST_STRONG_ISOLATE.'/algamaa-oalagraaaat-alakadymy/alkbol-oalthoyl/altskyn'.POP_DIRECTIONAL_ISOLATE)
        ->and($text)->toContain('«'.FIRST_STRONG_ISOLATE)
        ->and($text)->toContain(POP_DIRECTIONAL_ISOLATE.'»');
});

it('isolates the search query and keeps the Arabic label and checkmark outside the island', function () {
    $api = new FakeTelegramApi;

    progressFor($api)->note(toolCallEvent('search_content', [
        'query' => '"السنة الأولى" "مشتركة" كلية الحاسبات',
    ]));

    $text = lastProgressText($api);

    expect($text)
        ->toContain('🔎 يبحث: «'.FIRST_STRONG_ISOLATE.'"السنة الأولى" "مشتركة" كلية الحاسبات'.POP_DIRECTIONAL_ISOLATE.'»')
        ->and($text)->toContain('…');
});

it('isolates the mixed-language reasoning tail', function () {
    $api = new FakeTelegramApi;

    progressFor($api)->note(new ReasoningDelta('evt-2', 'reason-1', 'المواد: MTH1182T و CS1013T و C++. So the plan', 0));

    $text = lastProgressText($api);

    expect($text)
        ->toContain('🧠 '.FIRST_STRONG_ISOLATE)
        ->and($text)->toContain('C++. So the plan'.POP_DIRECTIONAL_ISOLATE);
});

it('collapses each snapshot into an expandable blockquote with escaped content', function () {
    $api = new FakeTelegramApi;

    progressFor($api)->note(toolCallEvent('search_content', ['query' => 'a < b & c']));

    $edit = $api->editedMessages[array_key_last($api->editedMessages)];

    expect($edit['parse_mode'])->toBe('HTML')
        ->and($edit['text'])->toStartWith('<blockquote expandable>')
        ->and($edit['text'])->toEndWith('</blockquote>')
        ->and($edit['text'])->toContain('a &lt; b &amp; c');
});

it('gives every non-empty line a strong RTL base direction', function () {
    $api = new FakeTelegramApi;

    progressFor($api)->note(toolCallEvent('search_content', ['query' => 'السنة الأولى المشتركة']));

    $text = lastProgressText($api);

    expect($text)->toStartWith(RTL_MARK);

    foreach (explode("\n", $text) as $line) {
        if ($line !== '') {
            expect($line)->toStartWith(RTL_MARK);
        }
    }
});
