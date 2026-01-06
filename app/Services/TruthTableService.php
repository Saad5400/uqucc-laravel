<?php

namespace App\Services;

class TruthTableService
{
    /**
     * Parse a propositional logic formula and generate a truth table.
     *
     * @return array{variables: array<string>, table: array<array<mixed>>}
     */
    public function generate(string $formula): array
    {
        if (empty(trim($formula))) {
            return [
                'variables' => [],
                'table' => [],
            ];
        }

        // Normalize the formula
        $normalizedFormula = $this->normalizeFormula($formula);

        // Extract variables
        $variables = $this->extractVariables($normalizedFormula);

        // Generate all combinations of truth values
        $combinations = $this->generateCombinations(count($variables));

        // Build the truth table
        $table = [];
        foreach ($combinations as $combination) {
            $row = array_combine($variables, $combination);
            $result = $this->evaluateFormula($normalizedFormula, $row);
            $row['result'] = $result;
            $table[] = $row;
        }

        return [
            'variables' => $variables,
            'table' => $table,
        ];
    }

    /**
     * Normalize the formula to use standard operators.
     */
    protected function normalizeFormula(string $formula): string
    {
        // Replace common operator variants with standard symbols
        $replacements = [
            // AND operators
            '/\s+and\s+/i' => ' && ',
            '/\s*∧\s*/' => ' && ',
            '/\s*\/\\\\\s*/' => ' && ',

            // OR operators
            '/\s+or\s+/i' => ' || ',
            '/\s*∨\s*/' => ' || ',
            '/\s*\\\\\\/\s*/' => ' || ',

            // NOT operators
            '/\s*not\s+/i' => '!',
            '/\s*¬\s*/' => '!',
            '/\s*~\s*/' => '!',

            // IMPLIES operators
            '/\s*→\s*/' => ' => ',
            '/\s*->\s*/' => ' => ',
            '/\s+implies\s+/i' => ' => ',

            // BICONDITIONAL operators
            '/\s*↔\s*/' => ' <=> ',
            '/\s*<->\s*/' => ' <=> ',
            '/\s+iff\s+/i' => ' <=> ',

            // TRUE/FALSE constants
            '/\b(true|T|⊤)\b/i' => 'TRUE',
            '/\b(false|F|⊥)\b/i' => 'FALSE',
        ];

        $normalized = $formula;
        foreach ($replacements as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized);
        }

        return trim($normalized);
    }

    /**
     * Extract variable names from the formula.
     *
     * @return array<string>
     */
    protected function extractVariables(string $formula): array
    {
        // Remove operators and parentheses to find variables
        $cleaned = preg_replace('/[&|!()=<>\s]/', ' ', $formula);
        $cleaned = preg_replace('/\b(TRUE|FALSE)\b/', '', $cleaned);

        // Split into tokens and filter
        $tokens = preg_split('/\s+/', $cleaned, -1, PREG_SPLIT_NO_EMPTY);

        // Get unique variables and sort alphabetically
        $variables = array_unique($tokens);
        sort($variables);

        return array_values($variables);
    }

    /**
     * Generate all possible combinations of truth values for n variables.
     *
     * @return array<array<bool>>
     */
    protected function generateCombinations(int $count): array
    {
        if ($count === 0) {
            return [[]];
        }

        $totalRows = 2 ** $count;
        $combinations = [];

        for ($i = 0; $i < $totalRows; $i++) {
            $row = [];
            for ($j = $count - 1; $j >= 0; $j--) {
                $row[] = (bool) (($i >> $j) & 1);
            }
            $combinations[] = $row;
        }

        return $combinations;
    }

    /**
     * Evaluate the formula with given variable values.
     *
     * @param  array<string, bool>  $values
     */
    protected function evaluateFormula(string $formula, array $values): bool
    {
        // Replace TRUE/FALSE constants
        $expression = str_replace('TRUE', 'true', $formula);
        $expression = str_replace('FALSE', 'false', $expression);

        // Replace variables with their values
        foreach ($values as $variable => $value) {
            $valueStr = $value ? 'true' : 'false';
            // Use word boundaries to avoid partial matches
            $expression = preg_replace('/\b'.preg_quote($variable, '/').'\b/', $valueStr, $expression);
        }

        // Replace logical operators with PHP equivalents
        $expression = $this->convertToPhpExpression($expression);

        // Evaluate the expression safely
        return $this->safeEvaluate($expression);
    }

    /**
     * Convert logical operators to PHP equivalents.
     */
    protected function convertToPhpExpression(string $expression): string
    {
        // Handle biconditional (<=>) - (A <=> B) is equivalent to ((A && B) || (!A && !B))
        while (preg_match('/\(([^()]+)\s*<=>\s*([^()]+)\)/', $expression, $matches)) {
            $left = trim($matches[1]);
            $right = trim($matches[2]);
            $replacement = "((($left) && ($right)) || (!($left) && !($right)))";
            $expression = str_replace($matches[0], $replacement, $expression);
        }

        // Handle simple biconditional without parentheses
        $expression = preg_replace_callback('/(\w+)\s*<=>\s*(\w+)/', function ($matches) {
            $left = $matches[1];
            $right = $matches[2];

            return "((($left) && ($right)) || (!($left) && !($right)))";
        }, $expression);

        // Handle implication (=>) - (A => B) is equivalent to (!A || B)
        while (preg_match('/\(([^()]+)\s*=>\s*([^()]+)\)/', $expression, $matches)) {
            $left = trim($matches[1]);
            $right = trim($matches[2]);
            $replacement = "(!($left) || ($right))";
            $expression = str_replace($matches[0], $replacement, $expression);
        }

        // Handle simple implication without parentheses
        $expression = preg_replace_callback('/(\w+)\s*=>\s*(\w+)/', function ($matches) {
            $left = $matches[1];
            $right = $matches[2];

            return "(!($left) || ($right))";
        }, $expression);

        return $expression;
    }

    /**
     * Safely evaluate a boolean expression.
     */
    protected function safeEvaluate(string $expression): bool
    {
        // Validate that the expression only contains safe characters
        if (! preg_match('/^[\w\s()!&|]+$/', $expression)) {
            throw new \InvalidArgumentException('Invalid characters in expression');
        }

        // Use eval with caution - the expression has been sanitized
        try {
            $result = eval("return (bool) ($expression);");

            return (bool) $result;
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid formula: '.$e->getMessage());
        }
    }

    /**
     * Format the truth table as a text table (for bot/CLI output).
     */
    public function formatAsText(array $truthTable): string
    {
        if (empty($truthTable['variables'])) {
            return 'Invalid formula or no variables found.';
        }

        $variables = $truthTable['variables'];
        $table = $truthTable['table'];

        // Build header
        $header = array_merge($variables, ['Result']);
        $columnWidths = array_map(fn ($col) => max(strlen($col), 5), $header);

        // Build separator
        $separator = '+'.implode('+', array_map(fn ($w) => str_repeat('-', $w + 2), $columnWidths)).'+';

        // Build header row
        $headerRow = '|';
        foreach ($header as $i => $col) {
            $headerRow .= ' '.str_pad($col, $columnWidths[$i]).' |';
        }

        // Build data rows
        $rows = [];
        foreach ($table as $row) {
            $rowStr = '|';
            foreach ($variables as $i => $var) {
                $value = $row[$var] ? 'T' : 'F';
                $rowStr .= ' '.str_pad($value, $columnWidths[$i]).' |';
            }
            $result = $row['result'] ? 'T' : 'F';
            $rowStr .= ' '.str_pad($result, $columnWidths[count($variables)]).' |';
            $rows[] = $rowStr;
        }

        return $separator."\n".$headerRow."\n".$separator."\n".implode("\n", $rows)."\n".$separator;
    }
}
