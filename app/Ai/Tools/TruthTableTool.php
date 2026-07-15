<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\GatedByAiSettings;
use App\Services\Logic\FormulaError;
use App\Services\Logic\TruthTableGenerator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Truth table generation (جدول الصدق) — same engine as the site's truth table
 * tool and the Telegram bot command ({@see TruthTableGenerator}). Read-only,
 * pure computation.
 */
class TruthTableTool implements Tool
{
    use GatedByAiSettings;

    public function __construct(private readonly TruthTableGenerator $generator) {}

    public function description(): Stringable|string
    {
        return 'Generate the truth table of a propositional logic formula (توليد جدول الصواب لصيغة منطقية). '
            .'Connectives may be written in any common notation: ¬ ~ ! not, ∧ /\ && and, ↑ ⊼ nand, ∨ \/ || or, ↓ ⊽ nor, ⊕ ⊻ xor, '
            .'→ -> => implies, ↔ <-> iff, and the constants ⊤/T/true and ⊥/F/false. Example: "p /\ q -> ~r". '
            .'Returns the canonical formula, a monospace table with a column per variable and per subformula, '
            .'and whether the formula is a tautology or a contradiction. Use it instead of computing rows yourself. Supports up to '
            .TruthTableGenerator::MAX_VARIABLES.' variables.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->aiToolsAreDisabled()) {
            return $this->aiDisabledReply();
        }

        $formula = is_scalar($request['formula'] ?? null) ? trim((string) $request['formula']) : '';

        if ($formula === '') {
            return 'يرجى إدخال صيغة منطقية. Provide a propositional formula, e.g. "p /\ q -> ~r".';
        }

        try {
            $table = $this->generator->generate($formula);
        } catch (FormulaError $error) {
            return $error->getMessage();
        }

        return implode("\n", [
            "الصيغة (formula): {$table->formula}",
            '```',
            $table->toTextTable(),
            '```',
            $table->verdict(),
        ]);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'formula' => $schema->string()
                ->description('The propositional formula in any accepted notation, e.g. "p /\ q -> ~r", "p and q => not r", or "p ∧ q → ¬r".')
                ->required(),
        ];
    }
}
