export type DiffLineType = 'context' | 'add' | 'remove';

export interface DiffLine {
    type: DiffLineType;
    text: string;
    /** 1-based line number in the old text, or null for added lines. */
    oldNumber: number | null;
    /** 1-based line number in the new text, or null for removed lines. */
    newNumber: number | null;
}

/** Above this many cells the LCS table is skipped for a cheap block diff. */
const LCS_CELL_LIMIT = 4_000_000;

function splitLines(text: string): string[] {
    const normalized = text.replace(/\r\n/g, '\n').replace(/\n$/, '');

    return normalized === '' ? [] : normalized.split('\n');
}

/**
 * A line-level diff of two texts, GitHub-style: each line is unchanged
 * (context), added, or removed, carrying its old/new line numbers. Uses a
 * longest-common-subsequence table so runs of unchanged lines stay aligned;
 * falls back to a plain remove-all-then-add-all block for pathologically large
 * inputs where the table would be too costly.
 */
export function diffLines(oldText: string, newText: string): DiffLine[] {
    const a = splitLines(oldText);
    const b = splitLines(newText);
    const n = a.length;
    const m = b.length;

    if (n * m > LCS_CELL_LIMIT) {
        return [
            ...a.map((text, index): DiffLine => ({ type: 'remove', text, oldNumber: index + 1, newNumber: null })),
            ...b.map((text, index): DiffLine => ({ type: 'add', text, oldNumber: null, newNumber: index + 1 })),
        ];
    }

    // dp[i][j] = length of the LCS of a[i:] and b[j:].
    const dp: number[][] = Array.from({ length: n + 1 }, () => new Array<number>(m + 1).fill(0));

    for (let i = n - 1; i >= 0; i--) {
        for (let j = m - 1; j >= 0; j--) {
            dp[i][j] = a[i] === b[j] ? dp[i + 1][j + 1] + 1 : Math.max(dp[i + 1][j], dp[i][j + 1]);
        }
    }

    const result: DiffLine[] = [];
    let i = 0;
    let j = 0;
    let oldNumber = 1;
    let newNumber = 1;

    while (i < n && j < m) {
        if (a[i] === b[j]) {
            result.push({ type: 'context', text: a[i], oldNumber: oldNumber++, newNumber: newNumber++ });
            i++;
            j++;
        } else if (dp[i + 1][j] >= dp[i][j + 1]) {
            result.push({ type: 'remove', text: a[i], oldNumber: oldNumber++, newNumber: null });
            i++;
        } else {
            result.push({ type: 'add', text: b[j], oldNumber: null, newNumber: newNumber++ });
            j++;
        }
    }

    while (i < n) {
        result.push({ type: 'remove', text: a[i], oldNumber: oldNumber++, newNumber: null });
        i++;
    }

    while (j < m) {
        result.push({ type: 'add', text: b[j], oldNumber: null, newNumber: newNumber++ });
        j++;
    }

    return result;
}

/** Whether the two texts differ at all (after newline normalization). */
export function hasLineChanges(oldText: string, newText: string): boolean {
    return diffLines(oldText, newText).some((line) => line.type !== 'context');
}
