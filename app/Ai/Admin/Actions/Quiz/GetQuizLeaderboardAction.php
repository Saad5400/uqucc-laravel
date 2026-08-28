<?php

namespace App\Ai\Admin\Actions\Quiz;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Helpers\ArabicPlural;
use App\Models\QuizPlayer;
use App\Models\User;
use App\Services\Quiz\QuizLeaderboard;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;

/**
 * The daily-quiz leaderboards the bot shows in the group: this week's top
 * players and the top players of the last {@see QuizLeaderboard::WINDOW_DAYS}
 * days, with points and current streaks. Read-only.
 */
class GetQuizLeaderboardAction extends AdminAction
{
    private const LIMIT = 10;

    public function __construct(private readonly QuizLeaderboard $leaderboard) {}

    public function name(): string
    {
        return 'get_quiz_leaderboard';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'quiz';
    }

    public function description(): string
    {
        return 'Get the daily-quiz leaderboards — this week\'s top players and the top players of the last '
            .QuizLeaderboard::WINDOW_DAYS.' days, with points and streaks. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $weekly = $this->board(
            $this->leaderboard->weekly(self::LIMIT),
            fn (QuizPlayer $player): int => (int) $player->weekly_points,
        );

        $window = $this->board(
            $this->leaderboard->window(self::LIMIT),
            fn (QuizPlayer $player): int => (int) $player->window_points,
        );

        if ($weekly === null && $window === null) {
            return ActionResult::text('لا يوجد متصدرون بعد — لم يشارك أحد في سؤال اليوم.');
        }

        return ActionResult::text(implode("\n\n", array_filter([
            $weekly === null ? null : "📅 هذا الأسبوع:\n".$weekly,
            $window === null ? null : sprintf("🏆 آخر %d يوماً:\n", QuizLeaderboard::WINDOW_DAYS).$window,
        ])));
    }

    /**
     * @param  Collection<int, QuizPlayer>  $players
     * @param  callable(QuizPlayer): int  $points
     */
    private function board(Collection $players, callable $points): ?string
    {
        if ($players->isEmpty()) {
            return null;
        }

        return $players
            ->values()
            ->map(fn (QuizPlayer $player, int $index): string => sprintf(
                '%d. %s — %s (سلسلة: %s)',
                $index + 1,
                $player->displayName(),
                ArabicPlural::points($points($player)),
                ArabicPlural::days($player->current_streak),
            ))
            ->implode("\n");
    }
}
