<?php

namespace App\Services\Quiz;

use App\Models\QuizPlayer;
use App\Models\TelegramTeam;
use App\Models\TelegramTeamMember;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The daily quiz seen as a contest between a chat's {@see TelegramTeam}s: the
 * same {@see \App\Models\QuizAnswer} trail the individual boards are summed
 * from, grouped by who plays for whom.
 *
 * Teams are scoped to one chat, so a board only ever means something inside
 * the chat that owns the teams — the same player may play for a team in one
 * group and for none in another, and their points count wherever they are a
 * member.
 *
 * How a team is scored, and why not by the sum:
 *
 *  - The **sum** of its members' points ranks roster size. Our teams are
 *    majors, branches and cohorts, and they differ by an order of magnitude —
 *    the largest would top the board on the first day and stay there however
 *    well anyone else played, which is the fastest way to make every other
 *    team stop trying.
 *  - The **average over the whole roster** punishes a team for the members it
 *    has rather than the answers it gives, and it rewards purging quiet
 *    members. Nobody should ever gain by removing a teammate.
 *  - So: the **average over the members who actually played** that period. A
 *    ten-member team and a sixty-member team are on equal terms, a quiet
 *    member costs their team nothing, and the way to climb is to answer well
 *    and to get more teammates answering — which is the behaviour the whole
 *    feature exists to produce.
 *
 * Two guards keep that honest. A team is ranked only once
 * {@see self::MIN_ACTIVE_MEMBERS} of its members have played in the period,
 * so one strong player cannot carry an empty roster to the top; and equal
 * averages are broken by the number who played, so breadth wins ties.
 *
 * Nothing here is stored. Standings are derived on read, exactly like the
 * individual boards, so a late vote, a re-run or a team created mid-week can
 * never leave a stale total behind.
 */
class QuizTeamLeaderboard
{
    /** How many members must have played before a team is ranked at all. */
    public const MIN_ACTIVE_MEMBERS = 3;

    /**
     * Every team in the chat that at least one member played for in the
     * period, best first. Includes teams still short of the quorum — they
     * carry {@see QuizTeamStanding::qualifies()} false so a caller can rank
     * the eligible ones and still tell the others what they are missing.
     *
     * @return array<int, QuizTeamStanding>
     */
    public function forChat(int $chatId, CarbonInterface $from, ?CarbonInterface $through = null): array
    {
        $teams = TelegramTeam::query()
            ->where('chat_id', $chatId)
            ->withCount('members')
            ->get();

        if ($teams->isEmpty()) {
            return [];
        }

        $memberships = TelegramTeamMember::query()
            ->whereIn('team_id', $teams->modelKeys())
            ->get(['team_id', 'telegram_user_id']);

        if ($memberships->isEmpty()) {
            return [];
        }

        $played = $this->playedInPeriod(
            $memberships->pluck('telegram_user_id')->unique()->all(),
            $from,
            $through,
        );

        $standings = [];

        foreach ($teams as $team) {
            $points = 0;
            $active = 0;

            foreach ($memberships->where('team_id', $team->id) as $membership) {
                $result = $played[(int) $membership->telegram_user_id] ?? null;

                if ($result === null) {
                    continue;
                }

                $points += $result['points'];
                $active++;
            }

            if ($active === 0) {
                continue;
            }

            $standings[] = new QuizTeamStanding(
                team: $team,
                points: $points,
                activeMembers: $active,
                members: (int) $team->members_count,
            );
        }

        usort($standings, static fn (QuizTeamStanding $a, QuizTeamStanding $b): int => [$b->average(), $b->activeMembers, $a->team->name]
            <=> [$a->average(), $a->activeMembers, $b->team->name]);

        return $standings;
    }

    /**
     * What each of the given Telegram users scored in the period, keyed by
     * Telegram user id and holding only those who answered at least once — a
     * member who sat the period out is absent, which is what makes them
     * neither a numerator nor a denominator above.
     *
     * @param  array<int, int>  $telegramUserIds
     * @return array<int, array{points: int}>
     */
    private function playedInPeriod(array $telegramUserIds, CarbonInterface $from, ?CarbonInterface $through): array
    {
        $inPeriod = fn (Builder $query): Builder => $query->forQuizDates($from, $through);

        return QuizPlayer::query()
            ->whereIn('telegram_user_id', $telegramUserIds)
            ->whereHas('answers', $inPeriod)
            ->withSum(['answers as period_points' => $inPeriod], 'points')
            ->get()
            ->mapWithKeys(fn (QuizPlayer $player): array => [
                (int) $player->telegram_user_id => ['points' => (int) $player->period_points],
            ])
            ->all();
    }
}
