<?php

namespace App\Services\Logic;

/**
 * Recursive-descent parser for propositional logic formulas, accepting every
 * common notation for each connective (matching Stanford's CS103 truth-table
 * tool):
 *
 *  - not:  ¬  ~  !  not
 *  - and:  ∧  /\  &&  &  ^  ·  and
 *  - or:   ∨  \/  ||  |  or
 *  - implies:  →  ⇒  ->  =>  implies
 *  - iff:  ↔  ⇔  <->  <=>  iff
 *  - constants:  ⊤ T true   /   ⊥ F false
 *
 * Precedence, loosest to tightest: ↔, → (right-associative), ∨, ∧, ¬.
 * Variables are identifiers like p, q, rain (word connectives are reserved,
 * case-insensitively).
 */
class FormulaParser
{
    /**
     * Ordered token patterns — longer operators must match before their
     * one-character prefixes. Each pattern is anchored with \G and matched at
     * the current byte offset.
     *
     * @var list<array{string, string|null}>
     */
    private const TOKEN_PATTERNS = [
        ['/\G\s+/u', null],
        ['/\G(?:<->|<=>|↔|⇔)/u', 'IFF'],
        ['/\G(?:->|=>|→|⇒)/u', 'IMPLIES'],
        ['/\G(?:\/\\\\|&&|∧|·|\^|&)/u', 'AND'],
        ['/\G(?:\\\\\/|\|\||∨|\|)/u', 'OR'],
        ['/\G(?:¬|~|!)/u', 'NOT'],
        ['/\G⊤/u', 'TRUE'],
        ['/\G⊥/u', 'FALSE'],
        ['/\G\(/u', 'LPAREN'],
        ['/\G\)/u', 'RPAREN'],
        ['/\G[A-Za-z_][A-Za-z0-9_]*/u', 'WORD'],
    ];

    /**
     * Word forms of the connectives and constants, reserved case-insensitively.
     *
     * @var array<string, string>
     */
    private const RESERVED_WORDS = [
        'not' => 'NOT',
        'and' => 'AND',
        'or' => 'OR',
        'implies' => 'IMPLIES',
        'iff' => 'IFF',
        'true' => 'TRUE',
        'false' => 'FALSE',
    ];

    /** @var list<array{kind: string, text: string}> */
    private array $tokens = [];

    private int $position = 0;

    /**
     * Parse a formula in any accepted notation into its syntax tree.
     *
     * @throws FormulaError when the input is empty or not a well-formed formula
     */
    public function parse(string $input): Node
    {
        $this->tokens = $this->tokenize($input);
        $this->position = 0;

        if ($this->tokens === []) {
            throw new FormulaError('الصيغة فارغة — اكتب صيغة منطقية مثل p ∧ q. The formula is empty; write one like p ∧ q.');
        }

        $node = $this->parseIff();

        if ($this->position < count($this->tokens)) {
            $unexpected = $this->tokens[$this->position]['text'];

            throw new FormulaError("رمز غير متوقع «{$unexpected}» بعد نهاية الصيغة. Unexpected \"{$unexpected}\" after the end of the formula.");
        }

        return $node;
    }

    /**
     * @return list<array{kind: string, text: string}>
     */
    private function tokenize(string $input): array
    {
        $tokens = [];
        $offset = 0;
        $length = strlen($input);

        while ($offset < $length) {
            $matched = false;

            foreach (self::TOKEN_PATTERNS as [$pattern, $kind]) {
                if (preg_match($pattern, $input, $match, 0, $offset) !== 1) {
                    continue;
                }

                $text = $match[0];
                $offset += strlen($text);
                $matched = true;

                if ($kind === 'WORD') {
                    $tokens[] = $this->classifyWord($text);
                } elseif ($kind !== null) {
                    $tokens[] = ['kind' => $kind, 'text' => $text];
                }

                break;
            }

            if (! $matched) {
                $char = mb_substr(substr($input, $offset), 0, 1);

                throw new FormulaError("رمز غير معروف «{$char}». Unknown symbol \"{$char}\" — allowed: variables, ¬ ~ ! not, ∧ /\\ && and, ∨ \\/ || or, → -> =>, ↔ <->, T/F, parentheses.");
            }
        }

        return $tokens;
    }

    /**
     * An identifier is either a reserved word connective/constant (checked
     * case-insensitively, plus the bare uppercase T and F constants) or a
     * variable name.
     *
     * @return array{kind: string, text: string}
     */
    private function classifyWord(string $word): array
    {
        if ($word === 'T' || $word === 'F') {
            return ['kind' => $word === 'T' ? 'TRUE' : 'FALSE', 'text' => $word];
        }

        $kind = self::RESERVED_WORDS[strtolower($word)] ?? 'VAR';

        return ['kind' => $kind, 'text' => $word];
    }

    private function parseIff(): Node
    {
        $node = $this->parseImplies();

        while ($this->currentKind() === 'IFF') {
            $this->position++;
            $node = Node::binary(NodeKind::Iff, $node, $this->parseImplies());
        }

        return $node;
    }

    private function parseImplies(): Node
    {
        $node = $this->parseOr();

        if ($this->currentKind() === 'IMPLIES') {
            $this->position++;

            return Node::binary(NodeKind::Implies, $node, $this->parseImplies());
        }

        return $node;
    }

    private function parseOr(): Node
    {
        $node = $this->parseAnd();

        while ($this->currentKind() === 'OR') {
            $this->position++;
            $node = Node::binary(NodeKind::OrOp, $node, $this->parseAnd());
        }

        return $node;
    }

    private function parseAnd(): Node
    {
        $node = $this->parseUnary();

        while ($this->currentKind() === 'AND') {
            $this->position++;
            $node = Node::binary(NodeKind::AndOp, $node, $this->parseUnary());
        }

        return $node;
    }

    private function parseUnary(): Node
    {
        if ($this->currentKind() === 'NOT') {
            $this->position++;

            return Node::not($this->parseUnary());
        }

        return $this->parseAtom();
    }

    private function parseAtom(): Node
    {
        $token = $this->tokens[$this->position] ?? null;

        if ($token === null) {
            throw new FormulaError('الصيغة ناقصة — متوقع متغير أو قوس في نهايتها. The formula ends where a variable or "(" was expected.');
        }

        $this->position++;

        switch ($token['kind']) {
            case 'VAR':
                return Node::variable($token['text']);

            case 'TRUE':
                return Node::constant(true);

            case 'FALSE':
                return Node::constant(false);

            case 'LPAREN':
                $node = $this->parseIff();

                if ($this->currentKind() !== 'RPAREN') {
                    throw new FormulaError('قوس غير مغلق — كل قوس فتح يحتاج قوس إغلاق. Unclosed parenthesis: every "(" needs a matching ")".');
                }

                $this->position++;

                return $node;

            default:
                throw new FormulaError("رمز غير متوقع «{$token['text']}» — متوقع متغير أو قوس. Unexpected \"{$token['text']}\" where a variable or \"(\" was expected.");
        }
    }

    private function currentKind(): ?string
    {
        return $this->tokens[$this->position]['kind'] ?? null;
    }
}
