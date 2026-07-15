<?php

it('generates a truth table for a valid formula', function () {
    $response = $this->postJson('/adwat/jdwal-alsawab', ['formula' => 'p /\ q -> ~r']);

    $response->assertOk();
    $response->assertJson([
        'formula' => 'p ∧ q → ¬r',
        'variables' => ['p', 'q', 'r'],
        'columns' => ['p', 'q', 'r', 'p ∧ q', '¬r', 'p ∧ q → ¬r'],
        'is_tautology' => false,
        'is_contradiction' => false,
    ]);

    expect($response->json('rows'))->toHaveCount(8);
});

it('flags a tautology in the response', function () {
    $response = $this->postJson('/adwat/jdwal-alsawab', ['formula' => 'p or not p']);

    $response->assertOk();
    expect($response->json('is_tautology'))->toBeTrue();
});

it('returns 422 with a bilingual message for a malformed formula', function () {
    $response = $this->postJson('/adwat/jdwal-alsawab', ['formula' => 'p and (q or r']);

    $response->assertUnprocessable();
    expect($response->json('message'))->toContain('قوس غير مغلق');
});

it('returns 422 when too many variables are used', function () {
    $response = $this->postJson('/adwat/jdwal-alsawab', [
        'formula' => 'a or b or c or d or e or f2 or g or h or i',
    ]);

    $response->assertUnprocessable();
    expect($response->json('message'))->toContain('Too many variables');
});

it('validates the formula field', function (array $payload, string $errorField) {
    $this->postJson('/adwat/jdwal-alsawab', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorField);
})->with([
    'missing' => [[], 'formula'],
    'not a string' => [['formula' => ['p']], 'formula'],
    'too long' => [['formula' => str_repeat('p or ', 60).'p'], 'formula'],
]);
