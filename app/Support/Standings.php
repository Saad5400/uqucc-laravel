<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Collection;

/**
 * Ranking a board that can have ties, the way a sports table does it.
 *
 * Two rules, and both exist because a board that ignores ties is read as
 * unfair by the people on it:
 *
 *  - {@see places()} gives equal entries the same place, and skips the places
 *    they used up (1, 2, 2, 4). Numbering by row position instead handed one
 *    of two identical scores the silver and the other the bronze, and it
 *    disagreed with the place a player is told they hold — those are computed
 *    by score, not by position.
 *  - {@see limitWithTies()} never cuts a tied group in half. Taking the top
 *    ten of eleven equal scores would drop someone for their row number.
 *
 * The score a row is compared by is whatever the caller passes: points for a
 * player, and for a team its average together with the breadth that breaks an
 * equal average, so two entries share a place only when nothing separates
 * them.
 */
final class Standings
{
    /**
     * The first `$limit` entries of an already-sorted board, plus everyone
     * tied with the last of them.
     *
     * @template TValue
     *
     * @param  Collection<int, TValue>  $rows
     * @param  Closure(TValue): mixed  $score
     * @return Collection<int, TValue>
     */
    public static function limitWithTies(Collection $rows, int $limit, Closure $score): Collection
    {
        $rows = $rows->values();

        if ($limit <= 0 || $rows->count() <= $limit) {
            return $rows;
        }

        $cut = $score($rows[$limit - 1]);

        return $rows->takeWhile(
            fn (mixed $row, int $index): bool => $index < $limit || $score($row) === $cut,
        );
    }

    /**
     * The place of every row of an already-sorted board, in order and shared
     * by equal scores.
     *
     * @template TValue
     *
     * @param  Collection<int, TValue>  $rows
     * @param  Closure(TValue): mixed  $score
     * @return list<int>
     */
    public static function places(Collection $rows, Closure $score): array
    {
        $places = [];
        $previous = null;
        $place = 0;

        foreach ($rows->values() as $index => $row) {
            $current = $score($row);

            if ($index === 0 || $current !== $previous) {
                $place = $index + 1;
            }

            $places[] = $place;
            $previous = $current;
        }

        return $places;
    }
}
