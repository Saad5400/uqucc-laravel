<?php

namespace App\Services\Quiz;

use App\Models\QuizAnswer;
use App\Models\QuizPlayer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The daily-quiz standings, in one place: the weekly board (denormalized
 * counters on {@see QuizPlayer}, zeroed every week by `quiz:announce-weekly`)
 * and the rolling board — the last {@see self::WINDOW_DAYS} days, summed from
 * the {@see QuizAnswer} trail.
 *
 * The rolling board replaces the old all-time one deliberately. A lifetime
 * sum ranks seniority rather than play: once someone is a few hundred points
 * ahead, nobody can ever close the gap, so missing a day or two put a player
 * out of the race for good. A moving window ages every lead out, which keeps
 * the board winnable for anyone who plays the next month well. Lifetime
 * totals live on in `total_points` as a personal stat.
 *
 * Players on the rolling board carry a `window_points` attribute.
 */
class QuizLeaderboard
{
    public const WINDOW_DAYS = 30;

    public function hasPlayers(): bool
    {
        return QuizPlayer::query()->where('answers_count', '>', 0)->exists();
    }

    /**
     * @return Collection<int, QuizPlayer>
     */
    public function weekly(int $limit): Collection
    {
        return QuizPlayer::query()
            ->where('weekly_points', '>', 0)
            ->orderByDesc('weekly_points')
            ->orderByDesc('current_streak')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, QuizPlayer>
     */
    public function window(int $limit): Collection
    {
        $since = $this->windowStart();

        return QuizPlayer::query()
            ->withSum(
                ['answers as window_points' => fn (Builder $query) => $query->where('answered_at', '>=', $since)],
                'points',
            )
            ->whereHas('answers', fn (Builder $query) => $query->where('answered_at', '>=', $since))
            ->orderByDesc('window_points')
            ->orderByDesc('current_streak')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function weeklyRankFor(QuizPlayer $player): int
    {
        return QuizPlayer::query()->where('weekly_points', '>', $player->weekly_points)->count() + 1;
    }

    public function windowPointsFor(QuizPlayer $player): int
    {
        return (int) $player->answers()
            ->where('answered_at', '>=', $this->windowStart())
            ->sum('points');
    }

    /**
     * The player's place on the rolling board — how many players outscored
     * them inside the window, plus one. Ties share a rank.
     */
    public function windowRankFor(QuizPlayer $player): int
    {
        return QuizAnswer::query()
            ->where('answered_at', '>=', $this->windowStart())
            ->groupBy('quiz_player_id')
            ->havingRaw('SUM(points) > ?', [$this->windowPointsFor($player)])
            ->pluck('quiz_player_id')
            ->count() + 1;
    }

    public function windowStart(): CarbonInterface
    {
        return now()->subDays(self::WINDOW_DAYS);
    }
}
