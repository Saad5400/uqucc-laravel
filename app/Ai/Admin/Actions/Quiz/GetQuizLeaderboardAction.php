<?php

namespace App\Ai\Admin\Actions\Quiz;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Helpers\ArabicPlural;
use App\Models\QuizPlayer;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The daily-quiz leaderboards the bot shows in the group: this week's top
 * players and the all-time top players, with points and current streaks.
 * Read-only.
 */
class GetQuizLeaderboardAction extends AdminAction
{
    private const LIMIT = 10;

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
        return 'Get the daily-quiz leaderboards — this week\'s top players and the all-time top players '
            .'with points and streaks (عرض متصدري سؤال اليوم هذا الأسبوع وكل الأوقات). Read-only.';
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
        $weekly = $this->board('weekly_points');
        $allTime = $this->board('total_points');

        if ($weekly === null && $allTime === null) {
            return ActionResult::text('لا يوجد متصدرون بعد — لم يشارك أحد في سؤال اليوم.');
        }

        return ActionResult::text(implode("\n\n", array_filter([
            $weekly === null ? null : "📅 هذا الأسبوع:\n".$weekly,
            $allTime === null ? null : "🏆 كل الأوقات:\n".$allTime,
        ])));
    }

    private function board(string $column): ?string
    {
        $players = QuizPlayer::query()
            ->where($column, '>', 0)
            ->orderByDesc($column)
            ->orderByDesc('best_streak')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        if ($players->isEmpty()) {
            return null;
        }

        return $players
            ->values()
            ->map(fn (QuizPlayer $player, int $index): string => sprintf(
                '%d. %s — %s (سلسلة: %s)',
                $index + 1,
                $player->displayName(),
                ArabicPlural::points($player->{$column}),
                ArabicPlural::days($player->current_streak),
            ))
            ->implode("\n");
    }
}
