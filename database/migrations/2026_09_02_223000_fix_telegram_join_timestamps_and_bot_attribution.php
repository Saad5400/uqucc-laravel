<?php

use App\Models\TelegramInviteLink;
use App\Models\TelegramInviteLinkJoin;
use Illuminate\Database\Migrations\Migration;

/**
 * Repairs the first hours of join tracking.
 *
 * Joins recorded before this ran stored Telegram's Unix timestamp as UTC while
 * every other timestamp in the database is Asia/Riyadh, and joins through links
 * the bot created before tracking existed were credited to the bot itself
 * rather than left unattributed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $offsetMinutes = now()->utcOffset();
        $cutoff = now();

        TelegramInviteLinkJoin::query()
            ->where('created_at', '<=', $cutoff)
            ->each(function (TelegramInviteLinkJoin $join) use ($offsetMinutes): void {
                if ($offsetMinutes !== 0 && $join->joined_at !== null) {
                    $join->joined_at = $join->joined_at->addMinutes($offsetMinutes);
                }

                // Anything credited to an id that never requested a link through
                // the bot is the bot's own account standing in for the creator.
                if ($join->creator_telegram_user_id !== null
                    && $join->telegram_invite_link_id === null
                    && ! TelegramInviteLink::query()
                        ->where('creator_telegram_user_id', $join->creator_telegram_user_id)
                        ->exists()) {
                    $join->creator_telegram_user_id = null;
                }

                $join->save();
            });
    }

    public function down(): void
    {
        // One-off repair of already-written rows; nothing to roll back.
    }
};
