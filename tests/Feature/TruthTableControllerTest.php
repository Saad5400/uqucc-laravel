<?php

use function Pest\Laravel\postJson;
use function Pest\Laravel\get;

test('truth table page renders successfully', function () {
    $response = get('/tools/truth-table');

    $response->assertSuccessful();
});

test('generates truth table for valid formula', function () {
    $response = postJson('/api/truth-table/generate', [
        'formula' => 'p && q',
    ]);

    $response->assertSuccessful()
        ->assertJson([
            'success' => true,
        ])
        ->assertJsonStructure([
            'success',
            'data' => [
                'variables',
                'table',
            ],
        ]);

    $data = $response->json('data');
    expect($data['variables'])->toBe(['p', 'q']);
    expect($data['table'])->toHaveCount(4);
});

test('returns error for empty formula', function () {
    $response = postJson('/api/truth-table/generate', [
        'formula' => '',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['formula']);
});

test('returns error for missing formula', function () {
    $response = postJson('/api/truth-table/generate', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['formula']);
});

test('handles complex formulas correctly', function () {
    $response = postJson('/api/truth-table/generate', [
        'formula' => '(p && q) => r',
    ]);

    $response->assertSuccessful();

    $data = $response->json('data');
    expect($data['variables'])->toBe(['p', 'q', 'r']);
    expect($data['table'])->toHaveCount(8);
});

test('handles different operator notations', function () {
    $formulas = [
        'p and q',
        'p /\\ q',
        'p or q',
        'p \\/ q',
        'not p',
        '~p',
        'p => q',
        'p -> q',
    ];

    foreach ($formulas as $formula) {
        $response = postJson('/api/truth-table/generate', [
            'formula' => $formula,
        ]);

        $response->assertSuccessful();
    }
});
