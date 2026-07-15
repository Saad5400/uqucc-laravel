<?php

namespace App\Services\Logic;

/**
 * One node of a parsed propositional formula. Immutable; built only through
 * the named constructors so every shape is valid by construction.
 */
final readonly class Node
{
    /**
     * @param  list<Node>  $children  empty for atoms, one node for ¬, two for binary connectives
     * @param  string|null  $name  the variable name, only for {@see NodeKind::Variable}
     * @param  bool|null  $value  the constant value, only for {@see NodeKind::Constant}
     */
    private function __construct(
        public NodeKind $kind,
        public array $children = [],
        public ?string $name = null,
        public ?bool $value = null,
    ) {}

    public static function variable(string $name): self
    {
        return new self(NodeKind::Variable, name: $name);
    }

    public static function constant(bool $value): self
    {
        return new self(NodeKind::Constant, value: $value);
    }

    public static function not(Node $operand): self
    {
        return new self(NodeKind::Not, [$operand]);
    }

    public static function binary(NodeKind $kind, Node $left, Node $right): self
    {
        return new self($kind, [$left, $right]);
    }

    /**
     * Evaluate the formula under one row's variable assignment.
     *
     * @param  array<string, bool>  $assignment
     */
    public function evaluate(array $assignment): bool
    {
        return match ($this->kind) {
            NodeKind::Variable => $assignment[$this->name] ?? false,
            NodeKind::Constant => $this->value,
            NodeKind::Not => ! $this->children[0]->evaluate($assignment),
            NodeKind::AndOp => $this->children[0]->evaluate($assignment) && $this->children[1]->evaluate($assignment),
            NodeKind::NandOp => ! ($this->children[0]->evaluate($assignment) && $this->children[1]->evaluate($assignment)),
            NodeKind::OrOp => $this->children[0]->evaluate($assignment) || $this->children[1]->evaluate($assignment),
            NodeKind::NorOp => ! ($this->children[0]->evaluate($assignment) || $this->children[1]->evaluate($assignment)),
            NodeKind::XorOp => $this->children[0]->evaluate($assignment) !== $this->children[1]->evaluate($assignment),
            NodeKind::Implies => ! $this->children[0]->evaluate($assignment) || $this->children[1]->evaluate($assignment),
            NodeKind::Iff => $this->children[0]->evaluate($assignment) === $this->children[1]->evaluate($assignment),
        };
    }

    /**
     * Render back to the canonical notation (¬ ∧ ∨ → ↔ ⊤ ⊥) with only the
     * parentheses the structure requires.
     */
    public function render(): string
    {
        return match ($this->kind) {
            NodeKind::Variable => $this->name,
            NodeKind::Constant => $this->value ? '⊤' : '⊥',
            NodeKind::Not => '¬'.$this->renderChild($this->children[0], parenthesizeEqual: false),
            NodeKind::Implies => $this->renderChild($this->children[0], parenthesizeEqual: true)
                .' → '.$this->renderChild($this->children[1], parenthesizeEqual: false),
            NodeKind::NandOp, NodeKind::NorOp => $this->renderChild($this->children[0], parenthesizeEqual: false)
                .' '.$this->kind->symbol().' '
                .$this->renderChild($this->children[1], parenthesizeEqual: true),
            default => $this->renderChild($this->children[0], parenthesizeEqual: false)
                .' '.$this->kind->symbol().' '
                .$this->renderChild($this->children[1], parenthesizeEqual: false),
        };
    }

    /**
     * All variable names in first-appearance (left-to-right) order.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        if ($this->kind === NodeKind::Variable) {
            return [$this->name];
        }

        $names = [];

        foreach ($this->children as $child) {
            foreach ($child->variables() as $name) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Every compound (non-atom) subformula in post-order — children before
     * parents, the whole formula last — deduplicated by rendered form.
     *
     * @return list<Node>
     */
    public function subformulas(): array
    {
        $found = [];

        $this->collectSubformulas($found);

        return array_values($found);
    }

    /**
     * @param  array<string, Node>  $found  keyed by rendered form for deduplication
     */
    private function collectSubformulas(array &$found): void
    {
        foreach ($this->children as $child) {
            $child->collectSubformulas($found);
        }

        if ($this->kind !== NodeKind::Variable && $this->kind !== NodeKind::Constant) {
            $found[$this->render()] ??= $this;
        }
    }

    /**
     * Render a child, parenthesizing it when it binds more loosely than this
     * node — or equally for the left side of the right-associative →.
     */
    private function renderChild(Node $child, bool $parenthesizeEqual): string
    {
        $needsParens = $parenthesizeEqual
            ? $child->kind->precedence() <= $this->kind->precedence()
            : $child->kind->precedence() < $this->kind->precedence();

        return $needsParens ? '('.$child->render().')' : $child->render();
    }
}
