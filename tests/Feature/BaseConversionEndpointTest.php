<?php

it('converts a number between two bases', function () {
    $response = $this->postJson('/adwat/tahwel-alaadad', [
        'number' => '2AF',
        'from_base' => 16,
        'to_base' => 2,
    ]);

    $response->assertOk();
    $response->assertJson([
        'input' => '2AF',
        'from_base' => 16,
        'to_base' => 2,
        'result' => '1010101111',
        'decimal' => '687',
        'is_approximate' => false,
        'summary' => '2AF₁₆ = 1010101111₂',
    ]);

    expect($response->json('steps'))->toHaveCount(3)
        ->and($response->json('steps.0.title'))->toContain('توسيع المنازل')
        ->and($response->json('steps.0.columns'))->toBe(['الرقم', 'قيمته', 'وزن المنزلة', 'الناتج'])
        ->and($response->json('steps.0.rows.0'))->toBe(['2', '2', '16^2 = 256', '512'])
        ->and($response->json('steps.0.layout'))->toBe('table')
        ->and($response->json('steps.2.layout'))->toBe('strips')
        ->and($response->json('steps.0.lines'))->not->toBeEmpty();
});

it('flags an approximate result for a fraction that does not terminate', function () {
    $response = $this->postJson('/adwat/tahwel-alaadad', [
        'number' => '0.7',
        'from_base' => 10,
        'to_base' => 2,
    ]);

    $response->assertOk();
    expect($response->json('is_approximate'))->toBeTrue();
});

it('returns 422 with a bilingual message for a digit outside the base', function () {
    $response = $this->postJson('/adwat/tahwel-alaadad', [
        'number' => '12A',
        'from_base' => 10,
        'to_base' => 2,
    ]);

    $response->assertUnprocessable();
    expect($response->json('message'))->toContain('ليس رقمًا في الأساس 10');
});

it('validates the payload', function (array $payload, string $errorField) {
    $this->postJson('/adwat/tahwel-alaadad', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorField);
})->with([
    'missing number' => [['from_base' => 10, 'to_base' => 2], 'number'],
    'number not a string' => [['number' => ['1'], 'from_base' => 10, 'to_base' => 2], 'number'],
    'number too long' => [['number' => str_repeat('1', 81), 'from_base' => 10, 'to_base' => 2], 'number'],
    'missing source base' => [['number' => '10', 'to_base' => 2], 'from_base'],
    'missing target base' => [['number' => '10', 'from_base' => 10], 'to_base'],
    'source base out of range' => [['number' => '10', 'from_base' => 1, 'to_base' => 2], 'from_base'],
    'target base out of range' => [['number' => '10', 'from_base' => 10, 'to_base' => 37], 'to_base'],
]);
