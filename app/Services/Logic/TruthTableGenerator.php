<?php

namespace App\Services\Logic;

use InvalidArgumentException;
use function collect;

class TruthTableGenerator
{
    private const OPERATOR_PRECEDENCE = [
        'NOT' => 5,
        'AND' => 4,
        'OR' => 3,
        'XOR' => 2,
        'IMPLIES' => 2,
        'IFF' => 1,
    ];

    private const BINARY_OPERATORS = ['AND', 'OR', 'XOR', 'IMPLIES', 'IFF'];

    /**
     * @return array{variables: string[], columns: array<int, array{label: string, node: array}>, rows: array<int, array<string, bool>>, normalized: string}
     */
    public function generate(string $rawExpression): array
    {
        $tokens = $this->tokenize($rawExpression);
        if (empty($tokens)) {
            throw new InvalidArgumentException('الرجاء إدخال صيغة منطقية.');
        }

        $ast = $this->parse($tokens);

        $variables = $this->extractVariables($ast);
        sort($variables, SORT_STRING);

        $expressions = $this->collectExpressions($ast);
        $columns = [
            ...array_map(fn ($var) => ['label' => $var, 'node' => ['type' => 'VAR', 'name' => $var]], $variables),
            ...$expressions,
        ];

        $normalized = $this->formatNode($ast);
        if (! collect($columns)->contains(fn ($column) => $column['label'] === $normalized)) {
            $columns[] = ['label' => $normalized, 'node' => $ast];
        }

        $rowCount = max(1, 2 ** count($variables));
        $rows = [];

        for ($i = 0; $i < $rowCount; $i++) {
            $assignment = [];
            foreach ($variables as $index => $variable) {
                $bit = (($rowCount - 1 - $i) >> (count($variables) - $index - 1)) & 1;
                $assignment[$variable] = (bool) $bit;
            }

            $row = [];
            foreach ($columns as $column) {
                $row[$column['label']] = $column['node']['type'] === 'VAR'
                    ? $assignment[$column['label']]
                    : $this->evaluate($column['node'], $assignment);
            }
            $rows[] = $row;
        }

        return [
            'variables' => $variables,
            'columns' => $columns,
            'rows' => $rows,
            'normalized' => $normalized,
        ];
    }

    /**
     * @return array<int, array{type: string, value?: bool|string}>
     */
    private function tokenize(string $input): array
    {
        $tokens = [];
        $remaining = trim($input);

        while ($remaining !== '') {
            if (preg_match('/^\s+/u', $remaining, $match)) {
                $remaining = mb_substr($remaining, mb_strlen($match[0]));
                continue;
            }

            $patterns = [
                ['/^<=>|^<->|^↔|^⟷/u', ['type' => 'IFF']],
                ['/^->|^=>|^→/u', ['type' => 'IMPLIES']],
                ['/^\/\\\\|^&&|^&|^∧/u', ['type' => 'AND']],
                ['/^\\\\\/|^\|\||^∨/u', ['type' => 'OR']],
                ['/^xor(?![A-Za-z0-9_])|^⊕|^\^/iu', ['type' => 'XOR']],
                ['/^!|^~|^¬/u', ['type' => 'NOT']],
                ['/^and(?![A-Za-z0-9_])/iu', ['type' => 'AND']],
                ['/^or(?![A-Za-z0-9_])/iu', ['type' => 'OR']],
                ['/^not(?![A-Za-z0-9_])/iu', ['type' => 'NOT']],
                ['/^xor(?![A-Za-z0-9_])/iu', ['type' => 'XOR']],
                ['/^\(/u', ['type' => 'LPAREN']],
                ['/^\)/u', ['type' => 'RPAREN']],
                ['/^⊤(?![A-Za-z0-9_])|^T(?![A-Za-z0-9_])/u', ['type' => 'CONST', 'value' => true]],
                ['/^⊥(?![A-Za-z0-9_])|^F(?![A-Za-z0-9_])/u', ['type' => 'CONST', 'value' => false]],
            ];

            $matched = false;
            foreach ($patterns as [$pattern, $token]) {
                if (preg_match($pattern, $remaining, $match)) {
                    $tokens[] = $token;
                    $remaining = mb_substr($remaining, mb_strlen($match[0]));
                    $matched = true;
                    break;
                }
            }

            if ($matched) {
                continue;
            }

            if (preg_match('/^[A-Za-z][A-Za-z0-9_]*/u', $remaining, $match)) {
                $tokens[] = ['type' => 'VAR', 'value' => $match[0]];
                $remaining = mb_substr($remaining, mb_strlen($match[0]));
                continue;
            }

            throw new InvalidArgumentException('رمز غير معروف بالقرب من: "'.mb_substr($remaining, 0, 10).'"');
        }

        return $tokens;
    }

    /**
     * @param array<int, array{type: string, value?: bool|string}> $tokens
     */
    private function parse(array $tokens): array
    {
        $position = 0;
        $length = count($tokens);

        $peek = fn () => $tokens[$position] ?? null;
        $consume = fn () => $tokens[$position++] ?? null;

        $parsePrimary = function () use (&$peek, &$consume, &$parseExpression): array {
            $token = $peek();
            if ($token === null) {
                throw new InvalidArgumentException('صيغة غير مكتملة.');
            }

            if ($token['type'] === 'LPAREN') {
                $consume();
                $expr = $parseExpression();
                if (($peek()['type'] ?? null) !== 'RPAREN') {
                    throw new InvalidArgumentException('القوس الأيمن مفقود.');
                }
                $consume();
                return $expr;
            }

            if ($token['type'] === 'VAR') {
                $consume();
                return ['type' => 'VAR', 'name' => $token['value']];
            }

            if ($token['type'] === 'CONST') {
                $consume();
                return ['type' => 'CONST', 'value' => (bool) $token['value']];
            }

            if ($token['type'] === 'NOT') {
                $consume();
                return ['type' => 'NOT', 'value' => $parsePrimary()];
            }

            throw new InvalidArgumentException('تعبير غير صالح، يرجى التحقق من الصيغة.');
        };

        $parseWithPrecedence = function (int $minPrecedence) use (&$parsePrimary, &$peek, &$consume, &$parseWithPrecedence): array {
            $left = $parsePrimary();

            while (true) {
                $token = $peek();
                if ($token === null || ! $this->isBinaryOperator($token['type'])) {
                    break;
                }

                $precedence = self::OPERATOR_PRECEDENCE[$token['type']];
                if ($precedence < $minPrecedence) {
                    break;
                }

                $consume();
                $nextMin = in_array($token['type'], ['IMPLIES', 'IFF'], true) ? $precedence : $precedence + 1;
                $right = $parseWithPrecedence($nextMin);
                $left = ['type' => $token['type'], 'left' => $left, 'right' => $right];
            }

            return $left;
        };

        $parseExpression = fn () => $parseWithPrecedence(1);

        $ast = $parseExpression();
        if ($position !== $length) {
            throw new InvalidArgumentException('لم يتم استهلاك كامل الصيغة، تحقق من الأقواس أو المشغلين.');
        }

        return $ast;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, bool>  $assignment
     */
    private function evaluate(array $node, array $assignment): bool
    {
        return match ($node['type']) {
            'VAR' => $assignment[$node['name']] ?? throw new InvalidArgumentException("لم يتم تعريف المتغير {$node['name']}."),
            'CONST' => (bool) $node['value'],
            'NOT' => ! $this->evaluate($node['value'], $assignment),
            'AND' => $this->evaluate($node['left'], $assignment) && $this->evaluate($node['right'], $assignment),
            'OR' => $this->evaluate($node['left'], $assignment) || $this->evaluate($node['right'], $assignment),
            'XOR' => $this->evaluate($node['left'], $assignment) xor $this->evaluate($node['right'], $assignment),
            'IMPLIES' => ! $this->evaluate($node['left'], $assignment) || $this->evaluate($node['right'], $assignment),
            'IFF' => $this->evaluate($node['left'], $assignment) === $this->evaluate($node['right'], $assignment),
            default => throw new InvalidArgumentException('نوع عقدة غير معروف.'),
        };
    }

    /**
     * @param array<string, mixed> $node
     */
    private function formatNode(array $node): string
    {
        $precedence = fn (string $operator) => self::OPERATOR_PRECEDENCE[$operator];
        $wrap = function (array $child, ?string $parentOp = null) use (&$wrap, $precedence): string {
            if ($child['type'] === 'VAR') {
                return (string) $child['name'];
            }
            if ($child['type'] === 'CONST') {
                return $child['value'] ? '⊤' : '⊥';
            }
            if ($child['type'] === 'NOT') {
                return '¬'.$wrap($child['value'], 'NOT');
            }

            $op = $child['type'];
            $needsParens = $parentOp !== null && $precedence($op) < $precedence($parentOp);
            $text = $wrap($child['left'], $op).' '.$this->operatorSymbol($op).' '.$wrap($child['right'], $op);

            return $needsParens ? "({$text})" : $text;
        };

        if (in_array($node['type'], ['VAR', 'CONST', 'NOT'], true)) {
            return $wrap($node);
        }

        return $wrap($node);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true>  $seen
     * @return array<int, array{label: string, node: array}>
     */
    private function collectExpressions(array $node, array &$seen = []): array
    {
        if (in_array($node['type'], ['VAR', 'CONST'], true)) {
            return [];
        }

        $expressions = [];
        if ($node['type'] === 'NOT') {
            $expressions = [...$expressions, ...$this->collectExpressions($node['value'], $seen)];
        } else {
            $expressions = [
                ...$expressions,
                ...$this->collectExpressions($node['left'], $seen),
                ...$this->collectExpressions($node['right'], $seen),
            ];
        }

        $label = $this->formatNode($node);
        if (! isset($seen[$label])) {
            $seen[$label] = true;
            $expressions[] = ['label' => $label, 'node' => $node];
        }

        return $expressions;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, true>  $vars
     * @return string[]
     */
    private function extractVariables(array $node, array &$vars = []): array
    {
        if ($node['type'] === 'VAR') {
            $vars[$node['name']] = true;
        } elseif ($node['type'] === 'NOT') {
            $this->extractVariables($node['value'], $vars);
        } elseif ($node['type'] !== 'CONST') {
            $this->extractVariables($node['left'], $vars);
            $this->extractVariables($node['right'], $vars);
        }

        return array_keys($vars);
    }

    private function isBinaryOperator(string $type): bool
    {
        return in_array($type, self::BINARY_OPERATORS, true);
    }

    private function operatorSymbol(string $operator): string
    {
        return match ($operator) {
            'AND' => '∧',
            'OR' => '∨',
            'XOR' => '⊕',
            'IMPLIES' => '→',
            'IFF' => '↔',
            default => $operator,
        };
    }
}
