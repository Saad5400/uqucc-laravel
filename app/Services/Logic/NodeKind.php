<?php

namespace App\Services\Logic;

/**
 * Every kind of node a parsed propositional formula can contain, with the
 * binding strength and canonical symbol used when rendering it back out.
 */
enum NodeKind
{
    case Variable;
    case Constant;
    case Not;
    case AndOp;
    case OrOp;
    case Implies;
    case Iff;

    /**
     * Binding strength — higher binds tighter. ↔ is loosest, ¬ is tightest,
     * atoms never need parentheses.
     */
    public function precedence(): int
    {
        return match ($this) {
            self::Iff => 1,
            self::Implies => 2,
            self::OrOp => 3,
            self::AndOp => 4,
            self::Not => 5,
            self::Variable, self::Constant => 6,
        };
    }

    /**
     * The canonical connective symbol used in rendered formulas and column
     * headers.
     */
    public function symbol(): string
    {
        return match ($this) {
            self::Not => '¬',
            self::AndOp => '∧',
            self::OrOp => '∨',
            self::Implies => '→',
            self::Iff => '↔',
            self::Variable, self::Constant => '',
        };
    }
}
