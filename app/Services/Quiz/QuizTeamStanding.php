<?php

namespace App\Services\Quiz;

use App\Models\TelegramTeam;

/**
 * One team's line on the team board: what its members scored inside a period,
 * how many of them actually played, and how many are on the roster.
 *
 * The rank is the average per player who showed up, never the raw total — see
 * {@see QuizTeamLeaderboard} for why the sum was the wrong measure.
 */
class QuizTeamStanding
{
    public function __construct(
        public readonly TelegramTeam $team,
        public readonly int $points,
        public readonly int $activeMembers,
        public readonly int $members,
    ) {}

    /**
     * The team's score: points per member who answered at least once in the
     * period, rounded. A team nobody played for scores zero rather than
     * dividing by it.
     */
    public function average(): int
    {
        return $this->activeMembers === 0 ? 0 : (int) round($this->points / $this->activeMembers);
    }

    /**
     * Whether the team is ranked at all. Below the quorum an average is not a
     * team's performance but one person's, so those teams are listed nowhere
     * and lose nothing by it.
     */
    public function qualifies(): bool
    {
        return $this->activeMembers >= QuizTeamLeaderboard::MIN_ACTIVE_MEMBERS;
    }
}
