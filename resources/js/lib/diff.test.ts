import { describe, expect, it } from 'vitest';
import { diffLines, hasLineChanges } from './diff';

describe('diffLines', () => {
    it('marks every line as context when the texts are identical', () => {
        const result = diffLines('one\ntwo', 'one\ntwo');

        expect(result.map((line) => line.type)).toEqual(['context', 'context']);
        expect(result[1]).toMatchObject({ oldNumber: 2, newNumber: 2, text: 'two' });
    });

    it('detects an inserted line while keeping surrounding context aligned', () => {
        const result = diffLines('one\nthree', 'one\ntwo\nthree');

        expect(result).toEqual([
            { type: 'context', text: 'one', oldNumber: 1, newNumber: 1 },
            { type: 'add', text: 'two', oldNumber: null, newNumber: 2 },
            { type: 'context', text: 'three', oldNumber: 2, newNumber: 3 },
        ]);
    });

    it('detects a removed line', () => {
        const result = diffLines('one\ntwo\nthree', 'one\nthree');

        expect(result).toEqual([
            { type: 'context', text: 'one', oldNumber: 1, newNumber: 1 },
            { type: 'remove', text: 'two', oldNumber: 2, newNumber: null },
            { type: 'context', text: 'three', oldNumber: 3, newNumber: 2 },
        ]);
    });

    it('treats a changed line as a remove followed by an add', () => {
        const result = diffLines('hello', 'world');

        expect(result).toEqual([
            { type: 'remove', text: 'hello', oldNumber: 1, newNumber: null },
            { type: 'add', text: 'world', oldNumber: null, newNumber: 1 },
        ]);
    });

    it('emits only additions when the old text is empty', () => {
        const result = diffLines('', 'a\nb');

        expect(result.map((line) => line.type)).toEqual(['add', 'add']);
        expect(result.map((line) => line.newNumber)).toEqual([1, 2]);
    });

    it('normalizes CRLF and ignores a single trailing newline', () => {
        expect(hasLineChanges('one\r\ntwo\n', 'one\ntwo')).toBe(false);
    });

    it('reports changes via hasLineChanges', () => {
        expect(hasLineChanges('a', 'b')).toBe(true);
        expect(hasLineChanges('a\nb', 'a\nb')).toBe(false);
    });
});
