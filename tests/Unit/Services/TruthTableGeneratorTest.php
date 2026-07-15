<?php

use App\Services\Logic\FormulaError;
use App\Services\Logic\FormulaParser;
use App\Services\Logic\TruthTableGenerator;

function truthTable(string $formula): \App\Services\Logic\TruthTable
{
    return new TruthTableGenerator(new FormulaParser)->generate($formula);
}

it('generates the table for the canonical example p ∧ q → ¬r', function () {
    $table = truthTable('p ∧ q → ¬r');

    expect($table->formula)->toBe('p ∧ q → ¬r')
        ->and($table->variables)->toBe(['p', 'q', 'r'])
        ->and($table->columns)->toBe(['p', 'q', 'r', 'p ∧ q', '¬r', 'p ∧ q → ¬r'])
        ->and($table->rows)->toHaveCount(8);

    // Only the all-true row falsifies the implication.
    expect($table->rows[0])->toBe([true, true, true, true, false, false])
        ->and($table->rows[1])->toBe([true, true, false, true, true, true])
        ->and($table->rows[7])->toBe([false, false, false, false, true, true]);
});

it('parses every notation for each connective identically', function (string $formula) {
    expect(truthTable($formula)->formula)->toBe('p ∧ q → ¬r');
})->with([
    'ascii art' => ['p /\ q -> ~r'],
    'words' => ['p and q => not r'],
    'programming' => ['p && q -> !r'],
    'unicode' => ['p ∧ q → ¬r'],
    'single char and bang' => ['p & q => !r'],
    'uppercase words' => ['p AND q IMPLIES NOT r'],
]);

it('parses or and iff notations identically', function (string $formula) {
    expect(truthTable($formula)->formula)->toBe('p ∨ q ↔ r');
})->with([
    'ascii art' => ['p \/ q <-> r'],
    'words' => ['p or q iff r'],
    'programming' => ['p || q <=> r'],
    'single pipe' => ['p | q <-> r'],
]);

it('gives and precedence over or', function () {
    $table = truthTable('p or q and r');

    expect($table->formula)->toBe('p ∨ q ∧ r')
        ->and($table->columns)->toContain('q ∧ r')
        ->and($table->columns)->not->toContain('p ∨ q');
});

it('makes implication right-associative', function () {
    // p -> q -> r must mean p -> (q -> r): with p=F it is vacuously true,
    // while (p -> q) -> r is false at p=F, q=T, r=F (row 5).
    $table = truthTable('p -> q -> r');
    $row = $table->rows[5];

    expect($table->formula)->toBe('p → q → r')
        ->and(end($row))->toBeTrue();

    $grouped = truthTable('(p -> q) -> r');
    $groupedRow = $grouped->rows[5];

    expect($grouped->formula)->toBe('(p → q) → r')
        ->and(end($groupedRow))->toBeFalse();
});

it('binds not tighter than and', function () {
    $table = truthTable('~p and q');

    expect($table->formula)->toBe('¬p ∧ q')
        ->and($table->rows[2])->toBe([false, true, true, true]); // p=F q=T: ¬p=T, ¬p∧q=T
});

it('accepts the T and F constants in every notation', function () {
    $table = truthTable('T \/ F');

    expect($table->formula)->toBe('⊤ ∨ ⊥')
        ->and($table->variables)->toBe([])
        ->and($table->columns)->toBe(['⊤ ∨ ⊥'])
        ->and($table->rows)->toBe([[true]]);

    expect(truthTable('true or false')->formula)->toBe('⊤ ∨ ⊥')
        ->and(truthTable('⊤ ∨ ⊥')->formula)->toBe('⊤ ∨ ⊥');
});

it('treats T and F as constants but lowercase t and f as variables', function () {
    expect(truthTable('T')->variables)->toBe([])
        ->and(truthTable('t')->variables)->toBe(['t'])
        ->and(truthTable('f')->variables)->toBe(['f']);
});

it('supports multi-letter variable names', function () {
    $table = truthTable('rain -> wet');

    expect($table->variables)->toBe(['rain', 'wet'])
        ->and($table->formula)->toBe('rain → wet');
});

it('orders variables by first appearance and rows with the first variable true on top', function () {
    $table = truthTable('q -> p');

    expect($table->variables)->toBe(['q', 'p'])
        ->and(array_column($table->rows, 0))->toBe([true, true, false, false]);
});

it('deduplicates repeated subformulas into one column', function () {
    $table = truthTable('(p and q) or (p and q)');

    expect($table->columns)->toBe(['p', 'q', 'p ∧ q', 'p ∧ q ∨ p ∧ q']);
});

it('classifies tautologies, contradictions, and contingent formulas', function () {
    expect(truthTable('p or not p')->isTautology())->toBeTrue()
        ->and(truthTable('p and not p')->isContradiction())->toBeTrue()
        ->and(truthTable('p -> q')->isTautology())->toBeFalse()
        ->and(truthTable('p -> q')->isContradiction())->toBeFalse();
});

it('renders the monospace text table', function () {
    $table = truthTable('p and q');

    expect($table->toTextTable())->toBe(implode("\n", [
        'p | q | p ∧ q',
        '--+---+------',
        'T | T |   T  ',
        'T | F |   F  ',
        'F | T |   F  ',
        'F | F |   F  ',
    ]));
});

it('exposes the array shape the web endpoint returns', function () {
    expect(truthTable('p')->toArray())->toBe([
        'formula' => 'p',
        'variables' => ['p'],
        'columns' => ['p'],
        'rows' => [[true], [false]],
        'is_tautology' => false,
        'is_contradiction' => false,
    ]);
});

it('rejects malformed formulas with a bilingual message', function (string $formula, string $fragment) {
    expect(fn () => truthTable($formula))->toThrow(FormulaError::class, $fragment);
})->with([
    'empty' => ['', 'فارغة'],
    'whitespace only' => ['   ', 'فارغة'],
    'unknown symbol' => ['p @ q', 'رمز غير معروف'],
    'dangling operator' => ['p and', 'ناقصة'],
    'leading binary operator' => ['and p', 'غير متوقع'],
    'unclosed parenthesis' => ['(p or q', 'قوس غير مغلق'],
    'trailing garbage' => ['p q', 'بعد نهاية الصيغة'],
    'stray closing parenthesis' => ['p)', 'بعد نهاية الصيغة'],
]);

it('rejects formulas with more than eight variables', function () {
    $formula = implode(' and ', ['a', 'b', 'c', 'd', 'e', 'f2', 'g', 'h', 'i']);

    expect(fn () => truthTable($formula))->toThrow(FormulaError::class, 'Too many variables');
});

it('accepts exactly eight variables (256 rows)', function () {
    $formula = implode(' and ', ['a', 'b', 'c', 'd', 'e', 'f2', 'g', 'h']);

    expect(truthTable($formula)->rows)->toHaveCount(256);
});
