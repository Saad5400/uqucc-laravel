<?php

namespace App\Services\Telegram\Handlers;

use App\Helpers\ArabicPlural;
use App\Models\QuizPlayer;
use App\Services\Quiz\QuizAnswerRecorder;
use App\Services\Quiz\QuizLeaderboard;
use Telegram\Bot\Objects\Message;

/**
 * «نقاطي» / /myscore — a member's own daily-quiz standing (weekly and
 * rolling-window rank and points, lifetime points, streak, accuracy, and
 * whether their streak freeze is ready). Both the request and the reply
 * self-delete after a short delay so personal stats don't clutter the group.
 */
class QuizMyScoreHandler extends BaseHandler
{
    /** How long the request and its reply live before being removed. */
    private const AUTODELETE_SECONDS = 30;

    private ?QuizLeaderboard $leaderboard = null;

    public function handle(Message $message): void
    {
        if (! $this->matches($message, '/^(?:\/(?:myscore|mypoints|score)(?:@\w+)?|نقاطي)$/u')) {
            return;
        }

        $this->trackCommand($message, 'quiz_my_score');

        $telegramUserId = $message->getFrom()?->getId();

        $player = $telegramUserId === null
            ? null
            : QuizPlayer::query()->where('telegram_user_id', $telegramUserId)->first();

        if ($player === null || $player->answers_count === 0) {
            $this->replyAndDelete(
                $message,
                'لم تشارك في سؤال اليوم بعد — أجب على السؤال حين يُنشر في المجموعة لتبدأ تجميع نقاطك! 🎯',
                delaySeconds: self::AUTODELETE_SECONDS,
            );

            return;
        }

        $text = sprintf(
            "👤 <b>نتيجتك في سؤال اليوم</b>\n".
            "هذا الأسبوع: %s (ترتيبك %d)\n".
            "آخر %d يوماً: %s (ترتيبك %d)\n".
            "الإجمالي منذ البداية: %s\n".
            "السلسلة الحالية: %s 🔥 (أفضل سلسلة: %s)\n".
            "الإجابات الصحيحة: %d من %d\n".
            '%s',
            ArabicPlural::points($player->weekly_points),
            $this->leaderboard()->weeklyRankFor($player),
            QuizLeaderboard::WINDOW_DAYS,
            ArabicPlural::points($this->leaderboard()->windowPointsFor($player)),
            $this->leaderboard()->windowRankFor($player),
            ArabicPlural::points($player->total_points),
            ArabicPlural::days($player->current_streak),
            ArabicPlural::days($player->best_streak),
            $player->correct_count,
            $player->answers_count,
            $this->freezeLine($player),
        );

        $this->replyAndDelete($message, $text, 'HTML', self::AUTODELETE_SECONDS);
    }

    /**
     * Teaches the streak freeze at the moment it matters: one missed quiz
     * every {@see QuizAnswerRecorder::FREEZE_COOLDOWN_DAYS} days keeps the
     * streak alive, and a player who just spent theirs should know it is gone
     * before they skip a second day.
     */
    private function freezeLine(QuizPlayer $player): string
    {
        $readyOn = $player->streak_frozen_on?->copy()->addDays(QuizAnswerRecorder::FREEZE_COOLDOWN_DAYS);

        if ($readyOn === null || $readyOn->isPast()) {
            return '🧊 تجميدة السلسلة جاهزة — تفويت سؤال واحد لن يكسر سلسلتك.';
        }

        return sprintf(
            '🧊 استُخدمت تجميدة السلسلة — تعود بعد %s.',
            ArabicPlural::days((int) ceil(now()->startOfDay()->diffInDays($readyOn, absolute: false))),
        );
    }

    private function leaderboard(): QuizLeaderboard
    {
        return $this->leaderboard ??= new QuizLeaderboard;
    }
}
