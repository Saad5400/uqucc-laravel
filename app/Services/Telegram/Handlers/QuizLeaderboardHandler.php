<?php

namespace App\Services\Telegram\Handlers;

use App\Helpers\ArabicPlural;
use App\Models\QuizPlayer;
use App\Services\Quiz\QuizLeaderboard;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Objects\Message;

/**
 * «المتصدرين» / /leaderboard — the daily quiz leaderboard: this week's top
 * ten, the top five of the last thirty days, and the asking player's own
 * numbers.
 */
class QuizLeaderboardHandler extends BaseHandler
{
    private const WEEKLY_LIMIT = 10;

    private const WINDOW_LIMIT = 5;

    /** Minimum seconds between leaderboard posts in the same chat. */
    private const COOLDOWN_SECONDS = 60;

    private ?QuizLeaderboard $leaderboard = null;

    public function handle(Message $message): void
    {
        if (! $this->matches($message, '/^(?:\/(?:leaderboard|top)(?:@\w+)?|المتصدرين|المتصدرون)$/u')) {
            return;
        }

        if ($this->onCooldown($message)) {
            return;
        }

        $this->trackCommand($message, 'quiz_leaderboard');

        if (! $this->leaderboard()->hasPlayers()) {
            $this->reply($message, 'لا يوجد متصدرون بعد — شارك في سؤال اليوم عندما يُنشر في المجموعة لتكون أول المتصدرين! 🏁');

            return;
        }

        $sections = [
            $this->weeklySection(),
            $this->windowSection(),
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
        $players = $this->leaderboard()->weekly(self::WEEKLY_LIMIT);

        if ($players->isEmpty()) {
            return "📅 <b>هذا الأسبوع</b>\nلم يسجّل أحد نقاطاً بعد هذا الأسبوع.";
        }

        return "📅 <b>هذا الأسبوع</b>\n".$this->rankedLines(
            $players,
            fn (QuizPlayer $player): int => (int) $player->weekly_points,
        );
    }

    /**
     * The rolling board. It replaced the all-time one so that a lead ages
     * out instead of compounding forever — the closing line says so, because
     * "why did the totals disappear?" is the first thing the group will ask.
     */
    private function windowSection(): ?string
    {
        $players = $this->leaderboard()->window(self::WINDOW_LIMIT);

        if ($players->isEmpty()) {
            return null;
        }

        $lines = $this->rankedLines(
            $players,
            fn (QuizPlayer $player): int => (int) $player->window_points,
        );

        return sprintf(
            "🏆 <b>آخر %d يوماً</b>\n%s\n<i>تُحتسب نقاط آخر %d يوماً فقط — الصدارة تُكتسب من جديد كل شهر.</i>",
            QuizLeaderboard::WINDOW_DAYS,
            $lines,
            QuizLeaderboard::WINDOW_DAYS,
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

        return sprintf(
            "👤 <b>نتيجتك</b>\nهذا الأسبوع: %s (ترتيبك %d)\nآخر %d يوماً: %s (ترتيبك %d)\nالسلسلة الحالية: %s 🔥 (أفضل سلسلة: %s)",
            ArabicPlural::points($this->leaderboard()->weeklyPointsFor($player)),
            $this->leaderboard()->weeklyRankFor($player),
            QuizLeaderboard::WINDOW_DAYS,
            ArabicPlural::points($this->leaderboard()->windowPointsFor($player)),
            $this->leaderboard()->windowRankFor($player),
            ArabicPlural::days($player->current_streak),
            ArabicPlural::days($player->best_streak),
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

    private function leaderboard(): QuizLeaderboard
    {
        return $this->leaderboard ??= app(QuizLeaderboard::class);
    }
}
