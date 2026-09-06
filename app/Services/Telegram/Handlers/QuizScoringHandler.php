<?php

namespace App\Services\Telegram\Handlers;

use App\Helpers\ArabicPlural;
use App\Helpers\Bidi;
use App\Services\Quiz\QuizAnswerRecorder;
use App\Services\Quiz\QuizLeaderboard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Objects\Message;

/**
 * «نقاط سؤال اليوم» — the whole scoring system on one screen: what an answer
 * is worth, what the streak adds, what the race pays, and how the boards are
 * counted.
 *
 * The rules were only ever discoverable by playing — a player saw points
 * arrive without knowing what earned them, which is the fastest way to make a
 * scoring system feel arbitrary. Rather than a written copy that drifts, every
 * number here is read from the constant that decides it, so the message is
 * wrong only if the scoring is.
 *
 * The card states the rules and nothing else — why each one is set where it is
 * belongs in the code, not in a message a player reads mid-game.
 *
 * It goes out under the same per-chat cooldown as the leaderboard: a rules
 * card is worth pinning, not worth repeating.
 */
class QuizScoringHandler extends BaseHandler
{
    /** Minimum seconds between two rules cards in the same chat. */
    private const COOLDOWN_SECONDS = 60;

    public function handle(Message $message): void
    {
        if (! $this->matches($message, '/^(?:\/(?:scoring|quizpoints)(?:@\w+)?|نقاط سؤال اليوم|نظام النقاط)$/u')) {
            return;
        }

        if ($this->onCooldown($message)) {
            return;
        }

        $this->trackCommand($message, 'quiz_scoring');

        $this->replyHtml($message, implode("\n", array_map(Bidi::line(...), $this->lines())));
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        return [
            '🎯 <b>نقاط سؤال اليوم</b>',
            '',
            '✅ إجابة صحيحة: '.ArabicPlural::points(QuizAnswerRecorder::POINTS_CORRECT),
            '🙋 إجابة خاطئة: '.ArabicPlural::points(QuizAnswerRecorder::POINTS_WRONG),
            sprintf(
                '🔥 السلسلة: %s لكل يوم متتالٍ، حتى %s',
                Bidi::ltr('+1'),
                Bidi::ltr('+'.QuizAnswerRecorder::STREAK_BONUS_CAP),
            ),
            sprintf(
                '⚡ السرعة: أسرع %s صحيحة تأخذ %s',
                ArabicPlural::answers(QuizAnswerRecorder::speedRanksCount()),
                Bidi::ltr(collect(QuizAnswerRecorder::SPEED_BONUSES)->map(fn (int $bonus): string => '+'.$bonus)->implode(' · ')),
            ),
            '',
            sprintf('🏅 أعلى يوم ممكن: %s.', ArabicPlural::points($this->bestPossibleDay())),
            sprintf(
                '🧊 تفويت سؤال واحد كل %s لا يكسر سلسلتك؛ تفويت سؤالين متتاليين — أو ثانٍ قبل انقضاء المدة — يعيدك إلى البداية.',
                ArabicPlural::days(QuizAnswerRecorder::FREEZE_COOLDOWN_DAYS),
            ),
            sprintf(
                '📅 لوحة الأسبوع تبدأ يوم %s، ولوحة آخر %d يوماً متجددة يومياً.',
                $this->weekStartDayName(),
                QuizLeaderboard::WINDOW_DAYS,
            ),
            '🛡️ الفريق يُرتَّب بمعدل نقاط من شارك من أعضائه لا بمجموعها.',
            '',
            '👤 «نقاطي» لنتيجتك · 🏆 «المتصدرين» للوحات',
        ];
    }

    /** Everything one answer can earn at once: correct, fastest, longest streak. */
    private function bestPossibleDay(): int
    {
        return QuizAnswerRecorder::POINTS_CORRECT
            + QuizAnswerRecorder::STREAK_BONUS_CAP
            + QuizAnswerRecorder::speedBonusFor(1);
    }

    /** The weekday the weekly board turns over on, named in Arabic. */
    private function weekStartDayName(): string
    {
        return CarbonImmutable::now()
            ->startOfWeek(QuizLeaderboard::WEEK_STARTS_ON)
            ->locale('ar')
            ->dayName;
    }

    /**
     * True when the card already went out in this chat within the window —
     * the same protection «المتصدرين» has, for the same reason.
     */
    private function onCooldown(Message $message): bool
    {
        $chatId = $message->getChat()?->getId();

        if ($chatId === null) {
            return false;
        }

        return ! Cache::add('quiz:scoring:cooldown:'.$chatId, true, self::COOLDOWN_SECONDS);
    }
}
