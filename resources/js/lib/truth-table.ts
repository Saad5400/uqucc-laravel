export type Operator = 'NOT' | 'AND' | 'OR' | 'IMPLIES' | 'IFF' | 'XOR'

export type Node =
    | { type: 'VAR'; name: string }
    | { type: 'CONST'; value: boolean }
    | { type: 'NOT'; value: Node }
    | { type: Exclude<Operator, 'NOT'>; left: Node; right: Node }

export interface TruthTableResult {
    variables: string[]
    columns: { label: string; node: Node }[]
    rows: Record<string, boolean>[]
    normalized: string
}

type Token =
    | { type: 'LPAREN' | 'RPAREN' }
    | { type: 'CONST'; value: boolean }
    | { type: 'VAR'; value: string }
    | { type: Operator }

const OPERATOR_PRECEDENCE: Record<Operator, number> = {
    NOT: 5,
    AND: 4,
    OR: 3,
    XOR: 2.5,
    IMPLIES: 2,
    IFF: 1
}

const BINARY_OPERATORS: Operator[] = ['AND', 'OR', 'XOR', 'IMPLIES', 'IFF']

const KEYWORD_PATTERN = (word: string) => new RegExp(`^${word}(?![A-Za-z0-9_])`, 'i')

export function tokenize(input: string): Token[] {
    const tokens: Token[] = []
    let remaining = input.trim()

    while (remaining.length > 0) {
        // Skip whitespace
        const whitespaceMatch = remaining.match(/^\s+/)
        if (whitespaceMatch) {
            remaining = remaining.slice(whitespaceMatch[0].length)
            continue
        }

        const checks: Array<[RegExp, () => Token]> = [
            [/^(<=>|<->|↔|⟷)/, () => ({ type: 'IFF' })],
            [/^(->|=>|→)/, () => ({ type: 'IMPLIES' })],
            [/^(\/\\|&&|&|∧)/, () => ({ type: 'AND' })],
            [/^(\\\/|\|\||∨)/, () => ({ type: 'OR' })],
            [/^(xor|⊕|\^)/i, () => ({ type: 'XOR' })],
            [/^(!|~|¬)/, () => ({ type: 'NOT' })],
            [KEYWORD_PATTERN('and'), () => ({ type: 'AND' })],
            [KEYWORD_PATTERN('or'), () => ({ type: 'OR' })],
            [KEYWORD_PATTERN('xor'), () => ({ type: 'XOR' })],
            [KEYWORD_PATTERN('not'), () => ({ type: 'NOT' })],
            [/^[\(]/, () => ({ type: 'LPAREN' })],
            [/^[\)]/, () => ({ type: 'RPAREN' })],
            [/^(⊤|T)(?![A-Za-z0-9_])/, () => ({ type: 'CONST', value: true })],
            [/^(⊥|F)(?![A-Za-z0-9_])/, () => ({ type: 'CONST', value: false })],
            [/^[A-Za-z][A-Za-z0-9_]*/, () => {
                const [match] = remaining.match(/^[A-Za-z][A-Za-z0-9_]*/) as RegExpMatchArray
                return { type: 'VAR', value: match }
            }]
        ]

        let matched = false
        for (const [pattern, factory] of checks) {
            const match = remaining.match(pattern)
            if (match) {
                tokens.push(factory())
                remaining = remaining.slice(match[0].length)
                matched = true
                break
            }
        }

        if (!matched) {
            throw new Error(`رمز غير معروف بالقرب من: "${remaining.slice(0, 10)}"`)
        }
    }

    return tokens
}

export function parse(tokens: Token[]): Node {
    let position = 0

    const peek = () => tokens[position]
    const consume = () => tokens[position++]

    const parsePrimary = (): Node => {
        const token = peek()
        if (!token) {
            throw new Error('صيغة غير مكتملة.')
        }

        if (token.type === 'LPAREN') {
            consume()
            const expr = parseExpression()
            if (!peek() || peek()?.type !== 'RPAREN') {
                throw new Error('القوس الأيمن مفقود.')
            }
            consume()
            return expr
        }

        if (token.type === 'VAR') {
            consume()
            return { type: 'VAR', name: token.value }
        }

        if (token.type === 'CONST') {
            consume()
            return { type: 'CONST', value: token.value }
        }

        if (token.type === 'NOT') {
            consume()
            return { type: 'NOT', value: parsePrimary() }
        }

        throw new Error('تعبير غير صالح، يرجى التحقق من الصيغة.')
    }

    const parseWithPrecedence = (minPrecedence: number): Node => {
        let left = parsePrimary()

        while (true) {
            const token = peek()
            if (!token || !isBinaryOperator(token)) {
                break
            }

            const precedence = OPERATOR_PRECEDENCE[token.type]
            if (precedence < minPrecedence) {
                break
            }

            consume()
            const right = parseWithPrecedence(precedence + (token.type === 'IMPLIES' || token.type === 'IFF' ? 0 : 1))
            left = { type: token.type, left, right } as Node
        }

        return left
    }

    const parseExpression = (): Node => parseWithPrecedence(1)

    const ast = parseExpression()
    if (position !== tokens.length) {
        throw new Error('لم يتم استهلاك كامل الصيغة، تحقق من الأقواس أو المشغلين.')
    }

    return ast
}

export function evaluate(node: Node, assignment: Record<string, boolean>): boolean {
    switch (node.type) {
        case 'VAR':
            if (!(node.name in assignment)) {
                throw new Error(`لم يتم تعريف المتغير ${node.name}.`)
            }
            return assignment[node.name]
        case 'CONST':
            return node.value
        case 'NOT':
            return !evaluate(node.value, assignment)
        case 'AND':
            return evaluate(node.left, assignment) && evaluate(node.right, assignment)
        case 'OR':
            return evaluate(node.left, assignment) || evaluate(node.right, assignment)
        case 'XOR':
            return evaluate(node.left, assignment) !== evaluate(node.right, assignment)
        case 'IMPLIES':
            return !evaluate(node.left, assignment) || evaluate(node.right, assignment)
        case 'IFF':
            return evaluate(node.left, assignment) === evaluate(node.right, assignment)
    }
}

export function formatNode(node: Node): string {
    const precedence = (operator: Operator) => OPERATOR_PRECEDENCE[operator]

    const wrap = (child: Node, parentOp?: Operator) => {
        if (child.type === 'VAR') return child.name
        if (child.type === 'CONST') return child.value ? '⊤' : '⊥'
        if (child.type === 'NOT') return `¬${wrap(child.value, 'NOT')}`
        const needsParens = parentOp !== undefined && precedence(getOperator(child)) < precedence(parentOp)
        const text = `${wrap(child.left, getOperator(child))} ${operatorSymbol(child.type)} ${wrap(child.right, getOperator(child))}`
        return needsParens ? `(${text})` : text
    }

    if (node.type === 'VAR') return node.name
    if (node.type === 'CONST') return node.value ? '⊤' : '⊥'
    if (node.type === 'NOT') return `¬${wrap(node.value, 'NOT')}`
    return wrap(node)
}

const operatorSymbol = (operator: Operator): string => {
    switch (operator) {
        case 'AND':
            return '∧'
        case 'OR':
            return '∨'
        case 'XOR':
            return '⊕'
        case 'IMPLIES':
            return '→'
        case 'IFF':
            return '↔'
        case 'NOT':
            return '¬'
    }
}

const getOperator = (node: Node): Operator => {
    if (node.type === 'NOT') return 'NOT'
    if (node.type === 'VAR' || node.type === 'CONST') {
        throw new Error('Leaf nodes do not have operators.')
    }
    return node.type
}

const isBinaryOperator = (token: Token): token is { type: Exclude<Operator, 'NOT'> } =>
    BINARY_OPERATORS.includes(token.type as Operator)

const extractVariables = (node: Node, vars = new Set<string>()): Set<string> => {
    if (node.type === 'VAR') {
        vars.add(node.name)
    } else if (node.type === 'NOT') {
        extractVariables(node.value, vars)
    } else if (node.type !== 'CONST') {
        extractVariables(node.left, vars)
        extractVariables(node.right, vars)
    }
    return vars
}

const collectExpressions = (node: Node, seen = new Set<string>()): { label: string; node: Node }[] => {
    if (node.type === 'VAR' || node.type === 'CONST') return []

    const expressions: { label: string; node: Node }[] = []
    if (node.type === 'NOT') {
        expressions.push(...collectExpressions(node.value, seen))
    } else {
        expressions.push(...collectExpressions(node.left, seen))
        expressions.push(...collectExpressions(node.right, seen))
    }

    const label = formatNode(node)
    if (!seen.has(label)) {
        seen.add(label)
        expressions.push({ label, node })
    }

    return expressions
}

export function generateTruthTable(rawExpression: string): TruthTableResult {
    const tokens = tokenize(rawExpression)
    if (tokens.length === 0) {
        throw new Error('الرجاء إدخال صيغة منطقية.')
    }

    const ast = parse(tokens)
    const variables = Array.from(extractVariables(ast)).sort((a, b) => a.localeCompare(b))
    const expressions = collectExpressions(ast)
    const columns = [...variables.map((v) => ({ label: v, node: { type: 'VAR', name: v } as Node })), ...expressions]

    const finalLabel = formatNode(ast)
    if (!columns.some((column) => column.label === finalLabel)) {
        columns.push({ label: finalLabel, node: ast })
    }

    const rowCount = Math.max(1, 2 ** variables.length)
    const rows: Record<string, boolean>[] = []

    for (let i = 0; i < rowCount; i++) {
        const assignment: Record<string, boolean> = {}
        variables.forEach((variable, index) => {
            const bit = (rowCount - 1 - i) >> (variables.length - index - 1)
            assignment[variable] = (bit & 1) === 1
        })

        const row: Record<string, boolean> = {}
        columns.forEach(({ label, node }) => {
            row[label] = node.type === 'VAR' ? assignment[label] : evaluate(node, assignment)
        })
        rows.push(row)
    }

    return {
        variables,
        columns,
        rows,
        normalized: formatNode(ast)
    }
}
