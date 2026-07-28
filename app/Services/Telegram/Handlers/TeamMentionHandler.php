<?php

namespace App\Services\Telegram\Handlers;

use App\Jobs\SendTelegramTeamMentions;
use App\Models\TelegramTeamMember;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Objects\Message;

/**
 * «منشن فريق العابدية» / «منشن فرق ف1 + ف2» — the mention blast. Multiple
 * teams mean intersection (members in ALL of them). The trigger must be the
 * entire message; a category name inside normal chatter never fires. Mentions
 * are clickable tg://user links so members are notified even without a public
 * username, batched per message, with a per-chat cooldown on the same team
 * combination so the group can't be spammed.
 */
class TeamMentionHandler extends BaseTeamHandler
{
    /** Mentions per message — Telegram stops notifying reliably beyond this. */
    private const MENTIONS_PER_MESSAGE = 15;

    /** Hard cap on batches per blast, to respect group flood limits. */
    private const MAX_BATCHES = 10;

    /** Seconds before the same team combination can be blasted again. */
    private const COOLDOWN_SECONDS = 300;

    private const COOLDOWN_PREFIX = 'telegram_teams:mention_cooldown:';

    public function handle(Message $message): void
    {
        if (! $this->matches($message, '/^منشن\s+(?:فريق|فرق)\s+(.+)$/u')) {
            return;
        }

        if (! $this->isGroupChat($message)) {
            $this->reply($message, self::GROUP_ONLY_MESSAGE);

            return;
        }

        $this->trackCommand($message, 'team_mention');

        preg_match('/^منشن\s+(?:فريق|فرق)\s+(.+)$/u', trim((string) $message->getText()), $matches);

        $chatId = (int) $message->getChat()->getId();
        $names = $this->parseNameList(trim($matches[1]));

        if ($names === []) {
            $this->reply($message, 'حدد الفريق: منشن فريق اسم الفريق');

            return;
        }

        [$teams, $missing] = $this->resolveTeams($chatId, $names);

        if ($missing !== []) {
            $this->replyHtml($message, 'الفرق التالية غير موجودة: '.$this->joinNames($missing)."\nاعرض الفرق المتاحة بالأمر: الفرق");

            return;
        }

        if ($remaining = $this->cooldownRemaining($chatId, $teams->pluck('id')->all())) {
            $this->notifyCooldownOnce($message, $chatId, $teams, $remaining);

            return;
        }

        $mentionables = $this->intersectMembers($teams->pluck('id')->all());

        if ($mentionables === []) {
            $this->reply($message, 'لا يوجد أعضاء مطابقون لهذا النداء حاليًا.');

            return;
        }

        $header = $this->header($teams, count($mentionables));
        $chunks = $this->mentionChunks($mentionables);
        $overflow = count($mentionables) - min(count($mentionables), self::MENTIONS_PER_MESSAGE * self::MAX_BATCHES);

        $firstBatch = array_shift($chunks);
        $response = $this->replyHtml($message, $header."\n".$firstBatch);

        if ($overflow > 0) {
            $chunks[] = "و{$overflow} أعضاء آخرين لم يُنادَ عليهم لتفادي إغراق المجموعة.";
        }

        if ($chunks !== []) {
            SendTelegramTeamMentions::dispatch(
                $chatId,
                (int) $response->getMessageId(),
                $chunks,
            );
        }
    }

    /**
     * Users present in every one of the given teams, with what is needed to
     * ping them.
     *
     * @param  array<int, int|string>  $teamIds
     * @return array<int, array{id: int, name: string, username: string|null}>
     */
    protected function intersectMembers(array $teamIds): array
    {
        $rows = TelegramTeamMember::query()
            ->whereIn('team_id', $teamIds)
            ->orderBy('id')
            ->get();

        return $rows
            ->groupBy('telegram_user_id')
            ->filter(fn ($memberships): bool => $memberships->pluck('team_id')->unique()->count() === count($teamIds))
            ->map(fn ($memberships): array => [
                'id' => (int) $memberships->first()->telegram_user_id,
                'name' => $memberships->first()->displayName(),
                'username' => $memberships->last()->username,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\TelegramTeam>  $teams
     */
    protected function header($teams, int $count): string
    {
        if ($teams->count() === 1) {
            return '📣 نداء لأعضاء فريق «'.$this->escapeHtml($teams->first()->name)."» ({$count}):";
        }

        $joined = $teams->map(fn ($team): string => $this->escapeHtml($team->name))->implode(' ∩ ');

        return "📣 نداء لأعضاء {$joined} ({$count}):";
    }

    /**
     * @param  array<int, array{id: int, name: string, username: string|null}>  $mentionables
     * @return array<int, string>
     */
    protected function mentionChunks(array $mentionables): array
    {
        $capped = array_slice($mentionables, 0, self::MENTIONS_PER_MESSAGE * self::MAX_BATCHES);

        return array_map(
            fn (array $batch): string => implode('، ', array_map(
                fn (array $member): string => $this->mentionUser($member['id'], $member['username'], $member['name']),
                $batch,
            )),
            array_chunk($capped, self::MENTIONS_PER_MESSAGE),
        );
    }

    /**
     * Remaining cooldown seconds for this exact team combination, or null
     * when the blast may proceed (which also starts a new window).
     *
     * @param  array<int, int|string>  $teamIds
     */
    protected function cooldownRemaining(int $chatId, array $teamIds): ?int
    {
        sort($teamIds);
        $key = self::COOLDOWN_PREFIX.$chatId.':'.implode(',', $teamIds);
        $expiresAt = now()->getTimestamp() + self::COOLDOWN_SECONDS;

        if (Cache::add($key, $expiresAt, self::COOLDOWN_SECONDS)) {
            return null;
        }

        $storedExpiry = Cache::get($key);
        $remaining = is_int($storedExpiry) ? $storedExpiry - now()->getTimestamp() : self::COOLDOWN_SECONDS;

        return max($remaining, 1);
    }

    /**
     * Tell the chat about the cooldown once per window, then stay silent —
     * the anti-spam notice must not become spam itself.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\TelegramTeam>  $teams
     */
    protected function notifyCooldownOnce(Message $message, int $chatId, $teams, int $remainingSeconds): void
    {
        $teamIds = $teams->pluck('id')->all();
        sort($teamIds);
        $noticeKey = self::COOLDOWN_PREFIX.$chatId.':'.implode(',', $teamIds).':notice';

        if (! Cache::add($noticeKey, true, $remainingSeconds)) {
            return;
        }

        $label = $teams->map(fn ($team): string => $team->name)->implode(' + ');
        $minutes = (int) ceil($remainingSeconds / 60);

        $this->reply($message, "⏳ تم النداء على «{$label}» قبل قليل. حاول بعد {$minutes} ".($minutes === 1 ? 'دقيقة' : 'دقائق').'.');
    }
}
