<?php

namespace App\Services\Telegram\Handlers;

use App\Helpers\ArabicPlural;
use App\Models\QuizPlayer;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Objects\Message;

/**
 * «المتصدرين» / /leaderboard — the daily quiz leaderboard: this week's top
 * ten, the all-time top five, and the asking player's own numbers.
 */
class QuizLeaderboardHandler extends BaseHandler
{
    private const WEEKLY_LIMIT = 10;

    private const ALL_TIME_LIMIT = 5;

    /** Minimum seconds between leaderboard posts in the same chat. */
    private const COOLDOWN_SECONDS = 60;

    public function handle(Message $message): void
    {
        if (! $this->matches($message, '/^(?:\/(?:leaderboard|top)(?:@\w+)?|المتصدرين|المتصدرون)$/u')) {
            return;
        }

        if ($this->onCooldown($message)) {
            return;
        }

        $this->trackCommand($message, 'quiz_leaderboard');

        if (! QuizPlayer::query()->where('answers_count', '>', 0)->exists()) {
            $this->reply($message, 'لا يوجد متصدرون بعد — شارك في سؤال اليوم عندما يُنشر في المجموعة لتكون أول المتصدرين! 🏁');

            return;
        }

        $sections = [
            $this->weeklySection(),
            $this->allTimeSection(),
            $this->playerSection($message),
        ];

        $this->replyHtml($message, implode("\n\n", array_filter($sections)));
    }

    /**
     * True when the leaderboard was already posted in this chat within the
     * cooldown window — keeps «المتصدرين» from being spammed into the group.
     * The first call in the window reserves the slot; the rest fall through
     * silently.
     */
    private function onCooldown(Message $message): bool
    {
        $chatId = $message->getChat()?->getId();

        if ($chatId === null) {
            return false;
        }

        return ! Cache::add('quiz:leaderboard:cooldown:'.$chatId, true, self::COOLDOWN_SECONDS);
    }

    private function weeklySection(): ?string
    {
        $players = QuizPlayer::query()
            ->where('weekly_points', '>', 0)
            ->orderByDesc('weekly_points')
            ->orderByDesc('current_streak')
            ->orderBy('id')
            ->limit(self::WEEKLY_LIMIT)
            ->get();

        if ($players->isEmpty()) {
            return "📅 <b>هذا الأسبوع</b>\nلم يسجّل أحد نقاطاً بعد هذا الأسبوع.";
        }

        return "📅 <b>هذا الأسبوع</b>\n".$this->rankedLines(
            $players,
            fn (QuizPlayer $player): int => $player->weekly_points,
        );
    }

    private function allTimeSection(): ?string
    {
        $players = QuizPlayer::query()
            ->where('total_points', '>', 0)
            ->orderByDesc('total_points')
            ->orderByDesc('best_streak')
            ->orderBy('id')
            ->limit(self::ALL_TIME_LIMIT)
            ->get();

        if ($players->isEmpty()) {
            return null;
        }

        return "🏆 <b>كل الأوقات</b>\n".$this->rankedLines(
            $players,
            fn (QuizPlayer $player): int => $player->total_points,
        );
    }

    /**
     * The asking player's own standing — only when they have played before.
     */
    private function playerSection(Message $message): ?string
    {
        $telegramUserId = $message->getFrom()?->getId();

        if ($telegramUserId === null) {
            return null;
        }

        $player = QuizPlayer::query()->where('telegram_user_id', $telegramUserId)->first();

        if ($player === null || $player->answers_count === 0) {
            return null;
        }

        $weeklyRank = QuizPlayer::query()->where('weekly_points', '>', $player->weekly_points)->count() + 1;

        return sprintf(
            "👤 <b>نتيجتك</b>\nترتيبك هذا الأسبوع: %d — نقاطك: %d (الكلية: %d)\nسلسلة الأيام الحالية: %d 🔥 (أفضل سلسلة: %d)",
            $weeklyRank,
            $player->weekly_points,
            $player->total_points,
            $player->current_streak,
            $player->best_streak,
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, QuizPlayer>  $players
     * @param  callable(QuizPlayer): int  $points
     */
    private function rankedLines(\Illuminate\Database\Eloquent\Collection $players, callable $points): string
    {
        $medals = ['🥇', '🥈', '🥉'];

        return $players
            ->values()
            ->map(fn (QuizPlayer $player, int $index): string => sprintf(
                '%s %s — %s',
                $medals[$index] ?? ($index + 1).'.',
                $this->escapeHtml($player->displayName()),
                ArabicPlural::points($points($player)),
            ))
            ->implode("\n");
    }
}
