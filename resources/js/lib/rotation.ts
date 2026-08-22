/**
 * Picking one item out of a list to show, spreading the load across the list
 * over many visits.
 *
 * Kept pure (and with an injectable source of randomness) so the behaviour that
 * actually matters — a re-roll never lands back on what is already on screen —
 * is testable without mounting anything.
 */

export interface Identifiable {
    id: number;
}

/**
 * Pick a random item, avoiding `currentId` when there is anything else to pick.
 *
 * A one-item list still returns that item: "no repeats" is a nicety, "always
 * return someone to contact" is the requirement.
 */
export function pickAnother<T extends Identifiable>(pool: T[], currentId: number | null = null, random: () => number = Math.random): T | null {
    if (pool.length === 0) {
        return null;
    }

    const candidates = currentId === null ? pool : pool.filter((item) => item.id !== currentId);
    const source = candidates.length > 0 ? candidates : pool;

    return source[Math.floor(random() * source.length)] ?? null;
}
