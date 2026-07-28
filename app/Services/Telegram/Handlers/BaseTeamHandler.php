<?php

namespace App\Services\Telegram\Handlers;

use App\Helpers\ArabicNormalizer;
use App\Models\TelegramTeam;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Telegram\Bot\Objects\Message;
use Telegram\Bot\Objects\User;

/**
 * Shared vocabulary for the group-teams feature: parsing «ف1، ف2» name lists,
 * resolving them to a chat's {@see TelegramTeam} rows, and the consent
 * replay-block that keeps an old «انضم» message from being re-approved after
 * its author opted out or was removed.
 */
abstract class BaseTeamHandler extends BaseHandler
{
    protected const MAX_NAME_LENGTH = 32;

    protected const MAX_TEAMS_PER_CHAT = 100;

    protected const MAX_CATEGORIES_PER_CHAT = 30;

    protected const CONSENT_MAX_AGE_HOURS = 24;

    /**
     * The member's own consent message — «انضم فريق1، فريق2». Approval only
     * ever works as a reply to a message matching this shape.
     */
    protected const JOIN_PATTERN = '/^[اأ]نضم\s+(.+)$/u';

    protected const ADMIN_ONLY_MESSAGE = 'هذا الأمر متاح لمشرفي المجموعة فقط. 🔒';

    protected const GROUP_ONLY_MESSAGE = 'أوامر الفرق تعمل داخل المجموعات فقط.';

    /**
     * Cache prefix for opt-out timestamps. A consent message older than the
     * stored timestamp is dead even inside its 24h window, so an admin cannot
     * re-add someone from a message they sent before opting out. TTL matches
     * the consent window — anything older is invalid anyway.
     */
    protected const OPT_OUT_BLOCK_PREFIX = 'telegram_teams:optout:';

    /**
     * Split «ف1، ف2 + ف3» into unique trimmed team names.
     *
     * @return array<int, string>
     */
    protected function parseNameList(string $raw): array
    {
        $parts = preg_split('/[،,+]/u', $raw) ?: [];

        $names = array_map(static fn (string $part): string => trim($part), $parts);

        return array_values(array_unique(array_filter(
            $names,
            static fn (string $name): bool => $name !== '',
        )));
    }

    /**
     * Resolve names against one chat's teams, matching each name against team
     * names and aliases alike and ignoring the spelling variants the
     * normalizer folds. Order follows what the person typed, and a team named
     * twice (say by name and by alias in one message) resolves once.
     *
     * @param  array<int, string>  $names
     * @return array{Collection<int, TelegramTeam>, array<int, string>} the found teams and the missing names
     */
    protected function resolveTeams(int $chatId, array $names): array
    {
        $normalizedByName = [];

        foreach ($names as $name) {
            $normalizedByName[$name] = ArabicNormalizer::normalize($name);
        }

        $lookups = array_values(array_filter($normalizedByName, static fn (string $value): bool => $value !== ''));

        $candidates = $lookups === [] ? new Collection : TelegramTeam::query()
            ->where('chat_id', $chatId)
            ->where(fn ($query) => $query
                ->whereIn('normalized_name', $lookups)
                ->orWhereHas('aliases', fn ($aliases) => $aliases->whereIn('normalized_name', $lookups))
            )
            ->with('aliases')
            ->get();

        $teamsByNormalized = [];

        foreach ($candidates as $team) {
            $teamsByNormalized[$team->normalized_name] = $team;

            foreach ($team->aliases as $alias) {
                $teamsByNormalized[$alias->normalized_name] = $team;
            }
        }

        $resolved = [];
        $missing = [];

        foreach ($names as $name) {
            $team = $teamsByNormalized[$normalizedByName[$name]] ?? null;

            if ($team === null) {
                $missing[] = $name;

                continue;
            }

            $resolved[$team->id] ??= $team;
        }

        return [new Collection(array_values($resolved)), $missing];
    }

    /**
     * @param  iterable<int, string>  $names
     */
    protected function joinNames(iterable $names): string
    {
        $escaped = [];

        foreach ($names as $name) {
            $escaped[] = $this->escapeHtml($name);
        }

        return implode(' • ', $escaped);
    }

    protected function blockStaleConsents(int $chatId, int $userId, int $teamId): void
    {
        Cache::put(
            self::OPT_OUT_BLOCK_PREFIX."{$chatId}:{$userId}:{$teamId}",
            now()->getTimestamp(),
            now()->addHours(self::CONSENT_MAX_AGE_HOURS),
        );
    }

    protected function consentBlockedSince(int $chatId, int $userId, int $teamId): ?int
    {
        $timestamp = Cache::get(self::OPT_OUT_BLOCK_PREFIX."{$chatId}:{$userId}:{$teamId}");

        return is_int($timestamp) ? $timestamp : null;
    }

    /**
     * Reply attached to the sender's own message — unlike {@see reply()},
     * which redirects to the replied-to message when the trigger is itself a
     * reply. A join ack must stick to the join message the admin will answer.
     */
    protected function replyToSender(Message $message, string $text, ?string $parseMode = null): Message
    {
        $params = [
            'chat_id' => $message->getChat()->getId(),
            'text' => $text,
            'reply_to_message_id' => $message->getMessageId(),
        ];

        if ($parseMode) {
            $params['parse_mode'] = $parseMode;
        }

        return $this->telegram->sendMessage($params);
    }

    /**
     * A person's display name for bot replies, without the @ that would turn
     * a username into a live ping.
     */
    protected function personName(User $from): string
    {
        $name = trim((string) $from->getFirstName());

        if ($name !== '') {
            return $name;
        }

        $username = $from->getUsername();

        return $username !== null && $username !== '' ? (string) $username : 'عضو';
    }
}
