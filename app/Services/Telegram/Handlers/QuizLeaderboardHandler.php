<?php

namespace App\Services\Telegram\Handlers;

use App\Helpers\ArabicPlural;
use App\Helpers\Bidi;
use App\Models\QuizPlayer;
use App\Models\TelegramTeam;
use App\Services\Quiz\QuizLeaderboard;
use App\Services\Quiz\QuizTeamLeaderboard;
use App\Services\Quiz\QuizTeamStanding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Objects\Message;

/**
 * «المتصدرين» / /leaderboard — the daily quiz standings: this week's top
 * players, the top of the last thirty days, how the chat's teams are doing,
 * and the asking player's own numbers.
 *
 * Three things shape how the message is built:
 *
 *  - Each board goes inside an **expandable blockquote**, so the message
 *    arrives as a few tappable headers instead of thirty lines that bury the
 *    conversation. The reader opens the board they care about.
 *  - Every line is **direction-fenced** ({@see Bidi}). A ranked line mixes
 *    emoji, a name in any script and Western digits, and without the fencing
 *    the bidi algorithm reorders them differently for every name — which is
 *    what made the old board unreadable.
 *  - The **team board** is the point of the teams feature: it makes the
 *    daily question something a cohort wins together, and the closing line
 *    tells anyone still outside a team how to get in.
 */
class QuizLeaderboardHandler extends BaseHandler
{
    private const WEEKLY_LIMIT = 10;

    private const WINDOW_LIMIT = 5;

    private const TEAM_LIMIT = 5;

    /** Minimum seconds between leaderboard posts in the same chat. */
    private const COOLDOWN_SECONDS = 60;

    /** How members are told to join a team, everywhere it is mentioned. */
    public const JOIN_COMMAND = 'انضم';

    private ?QuizLeaderboard $leaderboard = null;

    private ?QuizTeamLeaderboard $teamLeaderboard = null;

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
            $this->teamSection($message),
            $this->playerSection($message),
            $this->joinInvitation($message),
        ];

        $this->replyHtml($message, implode("\n", array_filter($sections)));
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

    private function weeklySection(): string
    {
        $players = $this->leaderboard()->weekly(self::WEEKLY_LIMIT);

        if ($players->isEmpty()) {
            return $this->section('📅 <b>هذا الأسبوع</b>', [
                Bidi::line('لم يسجّل أحد نقاطاً بعد هذا الأسبوع.'),
            ]);
        }

        return $this->section('📅 <b>هذا الأسبوع</b>', $this->rankedLines(
            $players,
            fn (QuizPlayer $player): int => (int) $player->weekly_points,
        ));
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

        return $this->section(
            sprintf('🗓️ <b>آخر %d يوماً</b>', QuizLeaderboard::WINDOW_DAYS),
            $this->rankedLines($players, fn (QuizPlayer $player): int => (int) $player->window_points),
            sprintf('تُحتسب نقاط آخر %d يوماً فقط — الصدارة تُكتسب من جديد كل شهر.', QuizLeaderboard::WINDOW_DAYS),
        );
    }

    /**
     * How this chat's teams are doing this week — the same period as the
     * individual weekly board, so the two tell one story.
     *
     * A team is ranked by the average of the members who played, not by their
     * sum ({@see QuizTeamLeaderboard} explains why), and only once enough of
     * them have played. A chat whose teams are all short of that quorum still
     * gets the section: it names what is missing, which is the only thing
     * that would fix it.
     */
    private function teamSection(Message $message): ?string
    {
        $chatId = $this->teamChatId($message);

        if ($chatId === null) {
            return null;
        }

        $standings = $this->teamLeaderboard()->forChat($chatId, $this->leaderboard()->weekStart());
        $ranked = array_slice(
            array_filter($standings, static fn (QuizTeamStanding $standing): bool => $standing->qualifies()),
            0,
            self::TEAM_LIMIT,
        );

        if ($ranked === []) {
            return $this->section('🛡️ <b>الفرق هذا الأسبوع</b>', [
                Bidi::line(sprintf(
                    'لم يكتمل نصاب أي فريق بعد — يحتاج الفريق %s هذا الأسبوع ليدخل الترتيب.',
                    ArabicPlural::people(QuizTeamLeaderboard::MIN_ACTIVE_MEMBERS),
                )),
                Bidi::line('اجمعوا زملاءكم على سؤال اليوم! 🔥'),
            ]);
        }

        $medals = ['🥇', '🥈', '🥉'];

        $lines = [];

        foreach ($ranked as $index => $standing) {
            $lines[] = Bidi::line(sprintf(
                '%s %s — معدل %s · شارك %d من %d',
                $medals[$index] ?? Bidi::ltr(($index + 1).'.'),
                Bidi::isolate($this->escapeHtml($standing->team->name)),
                ArabicPlural::points($standing->average()),
                $standing->activeMembers,
                $standing->members,
            ));
        }

        return $this->section(
            '🛡️ <b>الفرق هذا الأسبوع</b>',
            $lines,
            'ترتيب الفرق بمعدل نقاط من شارك من أعضائه لا بمجموعها، فالفريق الكبير لا يفوز بعدده — ويلزمه '
                .ArabicPlural::people(QuizTeamLeaderboard::MIN_ACTIVE_MEMBERS).' على الأقل.',
        );
    }

    /**
     * The asking player's own standing — only when they have played before.
     * It stays outside a blockquote: it is three lines, and it is the part
     * the reader came for.
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

        $lines = [
            '👤 <b>نتيجتك</b>',
            sprintf(
                'هذا الأسبوع: %s (ترتيبك %d)',
                ArabicPlural::points($this->leaderboard()->weeklyPointsFor($player)),
                $this->leaderboard()->weeklyRankFor($player),
            ),
            sprintf(
                'آخر %d يوماً: %s (ترتيبك %d)',
                QuizLeaderboard::WINDOW_DAYS,
                ArabicPlural::points($this->leaderboard()->windowPointsFor($player)),
                $this->leaderboard()->windowRankFor($player),
            ),
            sprintf(
                'السلسلة الحالية: %s 🔥 (أفضل سلسلة: %s)',
                ArabicPlural::days($player->current_streak),
                ArabicPlural::days($player->best_streak),
            ),
        ];

        return "\n".implode("\n", array_map(Bidi::line(...), $lines));
    }

    /**
     * The closing call to action, in the one place everybody reads: the board
     * itself. Shown wherever the chat has teams to join — a member already in
     * one still needs to know how to pick up another.
     */
    private function joinInvitation(Message $message): ?string
    {
        if ($this->teamChatId($message) === null) {
            return null;
        }

        return Bidi::line('🤝 للانضمام إلى فرقك ومنافسة البقية أرسل: '.self::JOIN_COMMAND);
    }

    /**
     * The chat whose teams this message should show, or null when there are
     * none to show — teams live in one group, and a private chat has none.
     */
    private function teamChatId(Message $message): ?int
    {
        if (! $this->isGroupChat($message)) {
            return null;
        }

        $chatId = (int) $message->getChat()->getId();

        return TelegramTeam::query()->where('chat_id', $chatId)->exists() ? $chatId : null;
    }

    /**
     * One board as an expandable blockquote: collapsed to its header until
     * the reader taps it open.
     *
     * @param  array<int, string>  $lines
     */
    private function section(string $header, array $lines, ?string $footnote = null): string
    {
        $body = array_merge([Bidi::line($header)], $lines);

        if ($footnote !== null) {
            $body[] = Bidi::line('<i>'.$footnote.'</i>');
        }

        return '<blockquote expandable>'.implode("\n", $body).'</blockquote>';
    }

    /**
     * @param  Collection<int, QuizPlayer>  $players
     * @param  callable(QuizPlayer): int  $points
     * @return array<int, string>
     */
    private function rankedLines(Collection $players, callable $points): array
    {
        $medals = ['🥇', '🥈', '🥉'];

        return $players
            ->values()
            ->map(fn (QuizPlayer $player, int $index): string => Bidi::line(sprintf(
                '%s %s — %s',
                $medals[$index] ?? Bidi::ltr(($index + 1).'.'),
                Bidi::isolate($this->escapeHtml($player->displayName())),
                ArabicPlural::points($points($player)),
            )))
            ->all();
    }

    private function leaderboard(): QuizLeaderboard
    {
        return $this->leaderboard ??= app(QuizLeaderboard::class);
    }

    private function teamLeaderboard(): QuizTeamLeaderboard
    {
        return $this->teamLeaderboard ??= app(QuizTeamLeaderboard::class);
    }
}
