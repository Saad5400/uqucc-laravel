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
    case NandOp;
    case OrOp;
    case NorOp;
    case XorOp;
    case Implies;
    case Iff;

    /**
     * Binding strength — higher binds tighter. ↔ is loosest, ¬ is tightest,
     * atoms never need parentheses. Every connective has a distinct level so
     * rendering stays unambiguous: the or-family (∨ ↓ ⊕) binds looser than the
     * and-family (∧ ↑), matching the classic "and before or" convention.
     */
    public function precedence(): int
    {
        return match ($this) {
            self::Iff => 1,
            self::Implies => 2,
            self::OrOp => 3,
            self::NorOp => 4,
            self::XorOp => 5,
            self::AndOp => 6,
            self::NandOp => 7,
            self::Not => 8,
            self::Variable, self::Constant => 9,
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
            self::NandOp => '↑',
            self::OrOp => '∨',
            self::NorOp => '↓',
            self::XorOp => '⊕',
            self::Implies => '→',
            self::Iff => '↔',
            self::Variable, self::Constant => '',
        };
    }
}
