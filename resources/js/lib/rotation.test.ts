import { describe, expect, it } from 'vitest';
import { pickAnother } from './rotation';

const pool = [{ id: 1 }, { id: 2 }, { id: 3 }];

describe('pickAnother', () => {
    it('returns null for an empty pool', () => {
        expect(pickAnother([])).toBeNull();
    });

    it('picks the item the random source points at', () => {
        expect(pickAnother(pool, null, () => 0)).toEqual({ id: 1 });
        expect(pickAnother(pool, null, () => 0.99)).toEqual({ id: 3 });
    });

    it('never returns the item already on screen', () => {
        for (const current of [1, 2, 3]) {
            for (const roll of [0, 0.5, 0.99]) {
                expect(pickAnother(pool, current, () => roll)?.id).not.toBe(current);
            }
        }
    });

    it('can still reach every other item when one is excluded', () => {
        const reachable = new Set([pickAnother(pool, 2, () => 0)?.id, pickAnother(pool, 2, () => 0.99)?.id]);

        expect(reachable).toEqual(new Set([1, 3]));
    });

    it('returns the only item even when it is the one already shown', () => {
        expect(pickAnother([{ id: 7 }], 7, () => 0)).toEqual({ id: 7 });
    });
});
