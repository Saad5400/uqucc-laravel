<?php

namespace App\Services\Logic;

/**
 * The single source of truth for truth tables (جدول الصواب): the web tool, the
 * Telegram bot command, and the AI assistant tool all pass a raw formula
 * string here and render the same {@see TruthTable}.
 */
class TruthTableGenerator
{
    /**
     * 2^8 = 256 rows — plenty for coursework while keeping every surface's
     * output (and the JSON payload) a sane size.
     */
    public const MAX_VARIABLES = 8;

    public function __construct(private readonly FormulaParser $parser) {}

    /**
     * @throws FormulaError when the formula cannot be parsed or uses too many variables
     */
    public function generate(string $formula): TruthTable
    {
        $root = $this->parser->parse($formula);

        $variables = $root->variables();

        if (count($variables) > self::MAX_VARIABLES) {
            throw new FormulaError(
                'عدد المتغيرات كبير — الحد الأقصى '.self::MAX_VARIABLES.' متغيرات. '
                .'Too many variables: at most '.self::MAX_VARIABLES.' are supported ('.count($variables).' given).'
            );
        }

        $columnNodes = $this->columnNodes($root, $variables);
        $columns = array_map(fn (Node $node): string => $node->render(), $columnNodes);

        $rows = [];
        $rowCount = 2 ** count($variables);

        for ($row = 0; $row < $rowCount; $row++) {
            $assignment = $this->assignmentForRow($variables, $row);

            $rows[] = array_map(
                fn (Node $node): bool => $node->evaluate($assignment),
                $columnNodes,
            );
        }

        return new TruthTable(
            formula: $root->render(),
            variables: $variables,
            columns: $columns,
            rows: $rows,
        );
    }

    /**
     * One node per column: the variables, then every distinct compound
     * subformula innermost-first (the full formula ends up last). An atomic
     * formula with no variables (⊤, ⊥) still gets its own column.
     *
     * @param  list<string>  $variables
     * @return list<Node>
     */
    private function columnNodes(Node $root, array $variables): array
    {
        $nodes = array_map(fn (string $name): Node => Node::variable($name), $variables);

        $subformulas = $root->subformulas();

        if ($subformulas === [] && $variables === []) {
            $subformulas = [$root];
        }

        return array_merge($nodes, $subformulas);
    }

    /**
     * The variable assignment for one row, enumerated so the first variable
     * holds T for the entire top half — the textbook ordering.
     *
     * @param  list<string>  $variables
     * @return array<string, bool>
     */
    private function assignmentForRow(array $variables, int $row): array
    {
        $assignment = [];
        $count = count($variables);

        foreach ($variables as $index => $name) {
            $assignment[$name] = (($row >> ($count - 1 - $index)) & 1) === 0;
        }

        return $assignment;
    }
}
