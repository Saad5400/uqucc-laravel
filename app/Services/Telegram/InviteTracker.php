<?php

namespace App\Services\Telegram;

use App\Models\TelegramInviteLink;
use App\Models\TelegramInviteLinkJoin;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Records who asks the bot for an invite link and who walks through it.
 *
 * Attribution comes from Telegram's `chat_member` update: when a member joins
 * through a link the bot created, the update carries that exact link, which is
 * matched back to the admin who requested it.
 */
class InviteTracker
{
    /**
     * Statuses that mean "this person is not in the chat".
     */
    protected const OUT_STATUSES = ['left', 'kicked'];

    /**
     * Persist a link the bot just created on an admin's behalf.
     *
     * @param  array<string, mixed>  $creator  Telegram user object of the requester
     */
    public function recordLink(
        int $chatId,
        ?string $chatTitle,
        string $inviteLink,
        array $creator,
        ?string $linkName = null,
        ?int $memberLimit = null,
    ): ?TelegramInviteLink {
        try {
            $telegramUserId = (int) ($creator['id'] ?? 0);

            return TelegramInviteLink::query()->updateOrCreate(
                ['invite_link' => $inviteLink],
                [
                    'chat_id' => $chatId,
                    'chat_title' => $chatTitle,
                    'link_name' => $linkName,
                    'creator_telegram_user_id' => $telegramUserId,
                    'creator_username' => $creator['username'] ?? null,
                    'creator_name' => trim(($creator['first_name'] ?? '').' '.($creator['last_name'] ?? '')) ?: null,
                    'creator_user_id' => User::findByTelegramId((string) $telegramUserId)?->id,
                    'member_limit' => $memberLimit,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Failed to record telegram invite link', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Record a join (or ignore anything that is not one) from a `chat_member` update.
     *
     * @param  array<string, mixed>  $chatMember  The raw `chat_member` update payload
     */
    public function recordChatMemberUpdate(array $chatMember): ?TelegramInviteLinkJoin
    {
        if (! $this->isJoin($chatMember)) {
            return null;
        }

        $chat = $chatMember['chat'] ?? [];
        $joiner = $chatMember['new_chat_member']['user'] ?? [];
        $link = $chatMember['invite_link'] ?? null;
        $actorId = (int) ($chatMember['from']['id'] ?? 0);
        $joinerId = (int) ($joiner['id'] ?? 0);

        if ($joinerId === 0) {
            return null;
        }

        $inviteLinkUrl = $link['invite_link'] ?? null;
        $tracked = $inviteLinkUrl
            ? TelegramInviteLink::query()->where('invite_link', $inviteLinkUrl)->first()
            : null;

        // Links created outside the bot still carry their Telegram-side creator,
        // so they are attributed too — just without a stored request behind them.
        $creatorTelegramId = $tracked?->creator_telegram_user_id
            ?? (isset($link['creator']['id']) ? (int) $link['creator']['id'] : null);

        try {
            $join = TelegramInviteLinkJoin::query()->updateOrCreate(
                [
                    'chat_id' => (int) ($chat['id'] ?? 0),
                    'joiner_telegram_user_id' => $joinerId,
                    'joined_at' => Carbon::createFromTimestamp((int) ($chatMember['date'] ?? time())),
                ],
                [
                    'telegram_invite_link_id' => $tracked?->id,
                    'invite_link' => $inviteLinkUrl,
                    'creator_telegram_user_id' => $creatorTelegramId,
                    'joiner_username' => $joiner['username'] ?? null,
                    'joiner_name' => trim(($joiner['first_name'] ?? '').' '.($joiner['last_name'] ?? '')) ?: null,
                    'source' => $this->resolveSource($inviteLinkUrl, $actorId, $joinerId),
                ]
            );

            if ($tracked && $join->wasRecentlyCreated) {
                $tracked->increment('joins_count');
            }

            return $join;
        } catch (\Throwable $e) {
            Log::error('Failed to record telegram join', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $chatMember
     */
    protected function isJoin(array $chatMember): bool
    {
        $old = $chatMember['old_chat_member'] ?? [];
        $new = $chatMember['new_chat_member'] ?? [];

        return $this->isOutside($old) && ! $this->isOutside($new);
    }

    /**
     * @param  array<string, mixed>  $member
     */
    protected function isOutside(array $member): bool
    {
        $status = $member['status'] ?? 'left';

        if (in_array($status, self::OUT_STATUSES, true)) {
            return true;
        }

        // A restricted member may or may not still be in the chat.
        return $status === 'restricted' && ($member['is_member'] ?? true) === false;
    }

    protected function resolveSource(?string $inviteLink, int $actorId, int $joinerId): string
    {
        if ($inviteLink !== null) {
            return 'invite_link';
        }

        return $actorId !== 0 && $actorId !== $joinerId ? 'added_by_admin' : 'self';
    }
}
