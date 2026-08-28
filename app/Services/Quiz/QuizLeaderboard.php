<?php

namespace App\Services\Quiz;

use App\Models\DailyQuiz;
use App\Models\QuizAnswer;
use App\Models\QuizPlayer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The daily-quiz standings: the weekly board and the rolling board — the last
 * {@see self::WINDOW_DAYS} quiz days. Both are summed from the
 * {@see QuizAnswer} trail, and both count an answer under the day of the
 * question it answers, never the moment the vote happened to arrive.
 *
 * That distinction is the whole design. A question goes out at 16:00 and
 * keeps taking votes until the next one replaces it the following afternoon,
 * so its answering window straddles midnight. Slicing the boards by the wall
 * clock split two members who answered the same question — one in the
 * evening, one after midnight — across the boundary: on the night the week
 * turned over it wiped the early answer and credited the late one to the new
 * week, so the slower player came out ahead. Counting by quiz day keeps one
 * question one contest — everyone who answers it lands on the same board, and
 * answering early is never worse than answering late.
 *
 * The week turns over on a quiz day for the same reason ({@see
 * self::WEEK_STARTS_ON}): the boundary falls between two questions, never
 * inside one. And nothing is ever zeroed — both boards are derived from the
 * trail, which makes the weekly announcement a message rather than a
 * mutation, and a mistimed or repeated run harmless.
 *
 * The rolling board replaces the old all-time one deliberately. A lifetime
 * sum ranks seniority rather than play: once someone is a few hundred points
 * ahead, nobody can ever close the gap, so missing a day or two put a player
 * out of the race for good. A moving window ages every lead out, which keeps
 * the board winnable for anyone who plays the next month well. Lifetime
 * totals live on in `total_points` as a personal stat.
 *
 * Players on the weekly board carry a `weekly_points` attribute; those on the
 * rolling board a `window_points` one.
 */
class QuizLeaderboard
{
    public const WINDOW_DAYS = 30;

    /**
     * The quiz day a new week opens on. Thursday: the outgoing week's last
     * question (Wednesday's) stops taking votes at the moment Thursday's goes
     * out, and the winners are announced that same evening.
     */
    public const WEEK_STARTS_ON = CarbonInterface::THURSDAY;

    public function hasPlayers(): bool
    {
        return QuizPlayer::query()->where('answers_count', '>', 0)->exists();
    }

    /**
     * @return Collection<int, QuizPlayer>
     */
    public function weekly(int $limit): Collection
    {
        return $this->board('weekly_points', $this->weekStart(), null, $limit);
    }

    /**
     * @return Collection<int, QuizPlayer>
     */
    public function window(int $limit): Collection
    {
        return $this->board('window_points', $this->windowStart(), null, $limit);
    }

    /**
     * The board for the week that has just ended — what the weekly
     * announcement crowns. Anchored to the calendar rather than to the
     * question in play, so a question posted late can neither make the
     * announcement repeat a week nor skip one.
     *
     * @return Collection<int, QuizPlayer>
     */
    public function lastWeek(int $limit): Collection
    {
        return $this->board('weekly_points', $this->lastWeekStart(), $this->lastWeekEnd(), $limit);
    }

    public function weeklyPointsFor(QuizPlayer $player): int
    {
        return $this->pointsFor($player, $this->weekStart(), null);
    }

    public function windowPointsFor(QuizPlayer $player): int
    {
        return $this->pointsFor($player, $this->windowStart(), null);
    }

    public function weeklyRankFor(QuizPlayer $player): int
    {
        return $this->rankFor($player, $this->weekStart(), null);
    }

    public function windowRankFor(QuizPlayer $player): int
    {
        return $this->rankFor($player, $this->windowStart(), null);
    }

    /** The first quiz day of the week now being played. */
    public function weekStart(): CarbonImmutable
    {
        return $this->currentQuizDate()->startOfWeek(self::WEEK_STARTS_ON);
    }

    /** The first quiz day of the week the announcement crowns. */
    public function lastWeekStart(): CarbonImmutable
    {
        return CarbonImmutable::parse(today())->startOfWeek(self::WEEK_STARTS_ON)->subWeek();
    }

    /** Its last quiz day — the question that closed as the new week opened. */
    public function lastWeekEnd(): CarbonImmutable
    {
        return $this->lastWeekStart()->addDays(6);
    }

    /** The oldest quiz day the rolling board still counts. */
    public function windowStart(): CarbonImmutable
    {
        return $this->currentQuizDate()->subDays(self::WINDOW_DAYS - 1);
    }

    /**
     * The quiz day in play: the day of the most recent question that actually
     * went out. It stays in play until the next question replaces it, so this
     * — not the calendar day — is when every period here turns over.
     */
    public function currentQuizDate(): CarbonImmutable
    {
        return CarbonImmutable::parse(DailyQuiz::lastPosted()?->quiz_date ?? today());
    }

    /**
     * One board: the players who answered a question inside the given stretch
     * of quiz days, ranked by what those answers were worth.
     *
     * @return Collection<int, QuizPlayer>
     */
    private function board(string $alias, CarbonInterface $from, ?CarbonInterface $through, int $limit): Collection
    {
        $inPeriod = fn (Builder $query): Builder => $query->forQuizDates($from, $through);

        return QuizPlayer::query()
            ->withSum(['answers as '.$alias => $inPeriod], 'points')
            ->whereHas('answers', $inPeriod)
            ->orderByDesc($alias)
            ->orderByDesc('current_streak')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function pointsFor(QuizPlayer $player, CarbonInterface $from, ?CarbonInterface $through): int
    {
        return (int) $player->answers()->forQuizDates($from, $through)->sum('points');
    }

    /**
     * The player's place on a board — how many players outscored them inside
     * the period, plus one. Ties share a rank.
     */
    private function rankFor(QuizPlayer $player, CarbonInterface $from, ?CarbonInterface $through): int
    {
        return QuizAnswer::query()
            ->forQuizDates($from, $through)
            ->groupBy('quiz_player_id')
            ->havingRaw('SUM(points) > ?', [$this->pointsFor($player, $from, $through)])
            ->pluck('quiz_player_id')
            ->count() + 1;
    }
}
