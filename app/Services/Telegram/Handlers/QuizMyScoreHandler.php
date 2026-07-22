<?php

namespace App\Services\Telegram\Handlers;

use App\Helpers\ArabicPlural;
use App\Models\QuizPlayer;
use Telegram\Bot\Objects\Message;

/**
 * «نقاطي» / /myscore — a member's own daily-quiz standing (weekly rank and
 * points, all-time points, streak, accuracy). Both the request and the reply
 * self-delete after a short delay so personal stats don't clutter the group.
 */
class QuizMyScoreHandler extends BaseHandler
{
    /** How long the request and its reply live before being removed. */
    private const AUTODELETE_SECONDS = 30;

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

        $weeklyRank = QuizPlayer::query()->where('weekly_points', '>', $player->weekly_points)->count() + 1;

        $text = sprintf(
            "👤 <b>نتيجتك في سؤال اليوم</b>\n".
            "هذا الأسبوع: %s (ترتيبك %d)\n".
            "الإجمالي: %s\n".
            "السلسلة الحالية: %s 🔥 (أفضل سلسلة: %s)\n".
            'الإجابات الصحيحة: %d من %d',
            ArabicPlural::points($player->weekly_points),
            $weeklyRank,
            ArabicPlural::points($player->total_points),
            ArabicPlural::days($player->current_streak),
            ArabicPlural::days($player->best_streak),
            $player->correct_count,
            $player->answers_count,
        );

        $this->replyAndDelete($message, $text, 'HTML', self::AUTODELETE_SECONDS);
    }
}
