<?php

use App\Ai\Gateway\ReasoningOpenRouterGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\OpenRouterProvider;

function finalStepBody(bool $isFinalStep, array $tools): array
{
    $events = app(Dispatcher::class);
    $gateway = new ReasoningOpenRouterGateway($events);

    $provider = new OpenRouterProvider(
        ['name' => 'openrouter', 'driver' => 'openrouter', 'key' => 'test-key'],
        $events,
    );

    $method = new ReflectionMethod($gateway, 'buildStepBody');

    return $method->invoke(
        $gateway,
        $provider,
        'deepseek/deepseek-v4-flash',
        'التعليمات',
        [new UserMessage('ايش المادة العشرون؟')],
        $tools,
        null,
        null,
        new StepContext(stepNumber: 11, isFinalStep: $isFinalStep, continuationToken: null),
    );
}

it('withholds tools and appends an answer-now message on the final step', function () {
    $body = finalStepBody(isFinalStep: true, tools: [new stdClass]);

    expect($body)->not->toHaveKey('tools')
        ->not->toHaveKey('tool_choice');

    $last = end($body['messages']);

    expect($last['role'])->toBe('user')
        ->and($last['content'])->toContain('انتهت خطوات استخدام الأدوات');
});

it('leaves non-final steps untouched', function () {
    $body = finalStepBody(isFinalStep: false, tools: []);

    $last = end($body['messages']);

    expect($last['content'])->not->toContain('انتهت خطوات استخدام الأدوات');
});
