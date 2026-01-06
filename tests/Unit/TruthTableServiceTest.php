<?php

use App\Services\TruthTableService;

test('generates truth table for simple AND operation', function () {
    $service = new TruthTableService();
    $result = $service->generate('p && q');

    expect($result['variables'])->toBe(['p', 'q']);
    expect($result['table'])->toHaveCount(4);
    expect($result['table'][0]['p'])->toBeFalse();
    expect($result['table'][0]['q'])->toBeFalse();
    expect($result['table'][0]['result'])->toBeFalse();
    expect($result['table'][3]['p'])->toBeTrue();
    expect($result['table'][3]['q'])->toBeTrue();
    expect($result['table'][3]['result'])->toBeTrue();
});

test('generates truth table for OR operation', function () {
    $service = new TruthTableService();
    $result = $service->generate('p || q');

    expect($result['variables'])->toBe(['p', 'q']);
    expect($result['table'])->toHaveCount(4);
    expect($result['table'][0]['result'])->toBeFalse(); // F || F = F
    expect($result['table'][1]['result'])->toBeTrue();  // F || T = T
    expect($result['table'][2]['result'])->toBeTrue();  // T || F = T
    expect($result['table'][3]['result'])->toBeTrue();  // T || T = T
});

test('generates truth table for NOT operation', function () {
    $service = new TruthTableService();
    $result = $service->generate('!p');

    expect($result['variables'])->toBe(['p']);
    expect($result['table'])->toHaveCount(2);
    expect($result['table'][0]['p'])->toBeFalse();
    expect($result['table'][0]['result'])->toBeTrue();
    expect($result['table'][1]['p'])->toBeTrue();
    expect($result['table'][1]['result'])->toBeFalse();
});

test('generates truth table for implication', function () {
    $service = new TruthTableService();
    $result = $service->generate('p => q');

    expect($result['variables'])->toBe(['p', 'q']);
    expect($result['table'])->toHaveCount(4);
    expect($result['table'][0]['result'])->toBeTrue();  // F => F = T
    expect($result['table'][1]['result'])->toBeTrue();  // F => T = T
    expect($result['table'][2]['result'])->toBeFalse(); // T => F = F
    expect($result['table'][3]['result'])->toBeTrue();  // T => T = T
});

test('handles alternative operator syntax', function () {
    $service = new TruthTableService();

    // Test AND alternatives
    $result1 = $service->generate('p and q');
    $result2 = $service->generate('p /\\ q');
    expect($result1['table'][3]['result'])->toBe($result2['table'][3]['result']);

    // Test OR alternatives
    $result3 = $service->generate('p or q');
    $result4 = $service->generate('p \\/ q');
    expect($result3['table'][3]['result'])->toBe($result4['table'][3]['result']);

    // Test NOT alternatives
    $result5 = $service->generate('not p');
    $result6 = $service->generate('~p');
    expect($result5['table'][0]['result'])->toBe($result6['table'][0]['result']);
});

test('generates truth table for complex formula', function () {
    $service = new TruthTableService();
    $result = $service->generate('(p && q) => r');

    expect($result['variables'])->toBe(['p', 'q', 'r']);
    expect($result['table'])->toHaveCount(8); // 2^3 = 8 rows
});

test('handles TRUE and FALSE constants', function () {
    $service = new TruthTableService();

    // Test TRUE constant
    $result1 = $service->generate('p && TRUE');
    expect($result1['table'][1]['result'])->toBeTrue(); // T && TRUE = T

    // Test FALSE constant
    $result2 = $service->generate('p && FALSE');
    expect($result2['table'][0]['result'])->toBeFalse(); // F && FALSE = F
    expect($result2['table'][1]['result'])->toBeFalse(); // T && FALSE = F
});

test('detects tautology', function () {
    $service = new TruthTableService();
    $result = $service->generate('p || !p');

    // All results should be true (tautology)
    foreach ($result['table'] as $row) {
        expect($row['result'])->toBeTrue();
    }
});

test('detects contradiction', function () {
    $service = new TruthTableService();
    $result = $service->generate('p && !p');

    // All results should be false (contradiction)
    foreach ($result['table'] as $row) {
        expect($row['result'])->toBeFalse();
    }
});

test('formats truth table as text', function () {
    $service = new TruthTableService();
    $truthTable = $service->generate('p && q');
    $formatted = $service->formatAsText($truthTable);

    expect($formatted)->toContain('p');
    expect($formatted)->toContain('q');
    expect($formatted)->toContain('Result');
    expect($formatted)->toContain('T');
    expect($formatted)->toContain('F');
});

test('handles empty formula', function () {
    $service = new TruthTableService();
    $result = $service->generate('');

    expect($result['variables'])->toBeEmpty();
    expect($result['table'])->toBeEmpty();
});

test('extracts variables correctly', function () {
    $service = new TruthTableService();
    $result = $service->generate('a && b || c');

    expect($result['variables'])->toBe(['a', 'b', 'c']);
});

test('handles biconditional operator', function () {
    $service = new TruthTableService();
    $result = $service->generate('p <=> q');

    expect($result['variables'])->toBe(['p', 'q']);
    expect($result['table'])->toHaveCount(4);
    expect($result['table'][0]['result'])->toBeTrue();  // F <=> F = T
    expect($result['table'][1]['result'])->toBeFalse(); // F <=> T = F
    expect($result['table'][2]['result'])->toBeFalse(); // T <=> F = F
    expect($result['table'][3]['result'])->toBeTrue();  // T <=> T = T
});

test('handles parentheses correctly', function () {
    $service = new TruthTableService();
    $result = $service->generate('(p || q) && r');

    expect($result['variables'])->toBe(['p', 'q', 'r']);
    expect($result['table'])->toHaveCount(8);
});
