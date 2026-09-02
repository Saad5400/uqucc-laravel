<?php

namespace App\Ai\Admin\Actions\Analytics;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Models\TelegramInviteLink;
use App\Models\TelegramInviteLinkJoin;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Invite-link attribution for the Telegram groups: who asked the bot for links
 * with «رابط», and who actually joined through them (من دعا من إلى المجموعة).
 * With `member`, answers the single question the panel is asked most — which
 * admin let this person in. Read-only. Mirrors the computations in
 * {@see \App\Http\Controllers\Manage\InviteAnalyticsController}.
 */
class GetInviteAnalyticsAction extends AdminAction
{
    /** Default window, in days, for the leaderboard. */
    private const DEFAULT_DAYS = 30;

    /** Upper bound for the requested window so a stray value stays sane. */
    private const MAX_DAYS = 3650;

    /** How many rows the leaderboard and the lookup each return. */
    private const LIMIT = 20;

    public function name(): string
    {
        return 'get_invite_analytics';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'analytics';
    }

    public function description(): string
    {
        return 'Telegram invite-link attribution: the leaderboard of admins ranked by how many members joined '
            .'through the links they requested with the «رابط» command, over the last N days (default 30). '
            .'Pass member (a name, @username or numeric Telegram id) to look one person up instead and get the '
            .'admin whose link let them in — a member name or an admin name both work. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()
                ->description('Optional window in days for the leaderboard (default 30).'),
            'member' => $schema->string()
                ->description('Optional name, @username or numeric Telegram id to look up instead of the leaderboard.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $days = (int) ($input['days'] ?? self::DEFAULT_DAYS);

        return [
            'days' => min($days < 1 ? self::DEFAULT_DAYS : $days, self::MAX_DAYS),
            'member' => trim((string) ($input['member'] ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $member = (string) $normalized['member'];

        return ActionResult::text(
            $member === ''
                ? $this->leaderboardText((int) $normalized['days'])
                : $this->lookupText($member)
        );
    }

    private function leaderboardText(int $days): string
    {
        $since = now()->subDays($days);

        $rows = TelegramInviteLinkJoin::query()
            ->selectRaw('creator_telegram_user_id, COUNT(*) as joins_total, MAX(joined_at) as last_join_at')
            ->whereNotNull('creator_telegram_user_id')
            ->where('joined_at', '>=', $since)
            ->groupBy('creator_telegram_user_id')
            ->orderByDesc('joins_total')
            ->limit(self::LIMIT)
            ->get();

        if ($rows->isEmpty()) {
            return 'لا توجد انضمامات مسجّلة عبر روابط الدعوة خلال آخر '.$days.' يوماً.';
        }

        $identities = $this->identities();
        $lines = ['ترتيب المشرفين حسب عدد من انضم عبر روابطهم (آخر '.$days.' يوماً):'];

        foreach ($rows as $index => $row) {
            $id = (int) $row->creator_telegram_user_id;
            $lines[] = sprintf(
                '%d. %s — %d عضواً، آخرهم %s.',
                $index + 1,
                $this->label($identities, $id),
                (int) $row->joins_total,
                (string) $row->last_join_at,
            );
        }

        return implode("\n", $lines);
    }

    private function lookupText(string $member): string
    {
        $needle = '%'.mb_strtolower(ltrim($member, '@')).'%';

        $inviterIds = TelegramInviteLink::query()
            ->whereRaw('LOWER(creator_username) LIKE ?', [$needle])
            ->orWhereRaw('LOWER(creator_name) LIKE ?', [$needle])
            ->orWhereRaw('CAST(creator_telegram_user_id AS TEXT) LIKE ?', [$needle])
            ->distinct()
            ->pluck('creator_telegram_user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $joins = TelegramInviteLinkJoin::query()
            ->where(function ($query) use ($needle, $inviterIds): void {
                $query->whereRaw('LOWER(joiner_username) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(joiner_name) LIKE ?', [$needle])
                    ->orWhereRaw('CAST(joiner_telegram_user_id AS TEXT) LIKE ?', [$needle]);

                if ($inviterIds !== []) {
                    $query->orWhereIn('creator_telegram_user_id', $inviterIds);
                }
            })
            ->latest('joined_at')
            ->limit(self::LIMIT)
            ->get();

        if ($joins->isEmpty()) {
            return 'لا يوجد انضمام مسجّل يطابق «'.$member.'». التتبّع يشمل من انضم بعد تفعيله فقط.';
        }

        $identities = $this->identities();
        $lines = ['الانضمامات المطابقة لـ «'.$member.'»:'];

        foreach ($joins as $join) {
            $inviterId = $join->creator_telegram_user_id;
            $lines[] = sprintf(
                '- %s (%d) انضم في %s %s.',
                $join->joiner_name ?: ($join->joiner_username ?? 'عضو'),
                $join->joiner_telegram_user_id,
                (string) $join->joined_at,
                $inviterId === null
                    ? 'دون رابط منسوب لمشرف'
                    : 'عبر رابط '.$this->label($identities, (int) $inviterId),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Best known display name per Telegram id, taken from the links they created.
     *
     * @return array<int, array{username: string|null, name: string|null}>
     */
    private function identities(): array
    {
        return TelegramInviteLink::query()
            ->selectRaw('creator_telegram_user_id, MAX(creator_username) as username, MAX(creator_name) as name')
            ->groupBy('creator_telegram_user_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->creator_telegram_user_id => [
                    'username' => $row->username,
                    'name' => $row->name,
                ],
            ])
            ->all();
    }

    /**
     * @param  array<int, array{username: string|null, name: string|null}>  $identities
     */
    private function label(array $identities, int $telegramUserId): string
    {
        $identity = $identities[$telegramUserId] ?? null;
        $name = $identity['name'] ?? null;
        $username = $identity['username'] ?? null;

        if ($name && $username) {
            return $name.' (@'.$username.')';
        }

        return $name ?: ($username ? '@'.$username : (string) $telegramUserId);
    }
}
