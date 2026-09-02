<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\BotCommandStat;
use App\Models\TelegramInviteLink;
use App\Models\TelegramInviteLinkJoin;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who brings people into the Telegram groups.
 *
 * Every link the «رابط» command hands out is stored with its requester, and
 * Telegram's chat_member update names the exact link each new member walked
 * through — so a join is credited to the admin whose link it was.
 */
class InviteAnalyticsController extends Controller
{
    /**
     * Windows offered by the period filter, in days. `null` means all time.
     */
    protected const PERIODS = [
        '24h' => 1,
        '7d' => 7,
        '30d' => 30,
        'all' => null,
    ];

    public function index(Request $request): Response
    {
        $period = array_key_exists((string) $request->query('period'), self::PERIODS)
            ? (string) $request->query('period')
            : 'all';

        $chatId = is_numeric($request->query('chat')) ? (int) $request->query('chat') : null;
        $search = trim((string) $request->query('q'));
        $since = $this->since($period);

        return Inertia::render('manage/invites/Index', [
            'filters' => [
                'period' => $period,
                'chat' => $chatId === null ? null : (string) $chatId,
                'q' => $search,
            ],
            'chats' => $this->chats(),
            'stats' => $this->stats($since, $chatId),
            'leaderboard' => $this->leaderboard($since, $chatId),
            'recentJoins' => Inertia::defer(fn (): array => $this->recentJoins($chatId, $search)),
            'preTrackingRequests' => Inertia::defer(fn (): array => $this->preTrackingRequests($chatId)),
        ]);
    }

    protected function since(string $period): ?Carbon
    {
        $days = self::PERIODS[$period];

        return $days === null ? null : now()->subDays($days);
    }

    /**
     * Groups that have produced at least one tracked link, for the chat filter.
     *
     * @return list<array{chat_id: string, title: string|null}>
     */
    protected function chats(): array
    {
        return TelegramInviteLink::query()
            ->selectRaw('chat_id, MAX(chat_title) as chat_title')
            ->groupBy('chat_id')
            ->orderByRaw('MAX(chat_title)')
            ->get()
            ->map(fn (TelegramInviteLink $link): array => [
                'chat_id' => (string) $link->chat_id,
                'title' => $link->chat_title,
            ])
            ->all();
    }

    /**
     * @return array{joins: int, attributedJoins: int, links: int, unusedLinks: int, inviters: int, conversion: int|null}
     */
    protected function stats(?Carbon $since, ?int $chatId): array
    {
        $joins = TelegramInviteLinkJoin::query()
            ->when($since, fn ($query) => $query->where('joined_at', '>=', $since))
            ->when($chatId, fn ($query) => $query->where('chat_id', $chatId));

        $attributed = (clone $joins)->whereNotNull('creator_telegram_user_id');

        $links = TelegramInviteLink::query()
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->when($chatId, fn ($query) => $query->where('chat_id', $chatId));

        $linksCount = (clone $links)->count();
        $joinsThroughLinks = (int) (clone $links)->sum('joins_count');

        return [
            'joins' => (clone $joins)->count(),
            'attributedJoins' => (clone $attributed)->count(),
            'inviters' => (clone $attributed)->distinct()->count('creator_telegram_user_id'),
            'links' => $linksCount,
            'unusedLinks' => (clone $links)->where('joins_count', 0)->count(),
            'conversion' => $linksCount === 0 ? null : (int) round($joinsThroughLinks / $linksCount * 100),
        ];
    }

    /**
     * Admins ranked by how many people actually joined through their links.
     *
     * @return list<array{
     *     telegram_user_id: string,
     *     username: string|null,
     *     name: string|null,
     *     joins: int,
     *     links: int,
     *     last_join_at: string|null,
     * }>
     */
    protected function leaderboard(?Carbon $since, ?int $chatId): array
    {
        $joins = TelegramInviteLinkJoin::query()
            ->selectRaw('creator_telegram_user_id, COUNT(*) as joins_total, MAX(joined_at) as last_join_at')
            ->whereNotNull('creator_telegram_user_id')
            ->when($since, fn ($query) => $query->where('joined_at', '>=', $since))
            ->when($chatId, fn ($query) => $query->where('chat_id', $chatId))
            ->groupBy('creator_telegram_user_id')
            ->orderByDesc('joins_total')
            ->get();

        $links = TelegramInviteLink::query()
            ->selectRaw('creator_telegram_user_id, COUNT(*) as links_total, MAX(creator_username) as username, MAX(creator_name) as name')
            ->when($since, fn ($query) => $query->where('created_at', '>=', $since))
            ->when($chatId, fn ($query) => $query->where('chat_id', $chatId))
            ->groupBy('creator_telegram_user_id')
            ->get()
            ->keyBy('creator_telegram_user_id');

        $identities = $this->identities();

        $rows = $joins->map(fn ($row): array => [
            'telegram_user_id' => (string) $row->creator_telegram_user_id,
            'username' => $identities[$row->creator_telegram_user_id]['username'] ?? null,
            'name' => $identities[$row->creator_telegram_user_id]['name'] ?? null,
            'joins' => (int) $row->joins_total,
            'links' => (int) ($links[$row->creator_telegram_user_id]->links_total ?? 0),
            'last_join_at' => $row->last_join_at ? Carbon::parse($row->last_join_at)->toISOString() : null,
        ]);

        // Admins who handed out links that nobody used yet still belong on the
        // board — a zero next to their name is the useful reading, not absence.
        $withoutJoins = $links
            ->reject(fn ($row) => $joins->contains('creator_telegram_user_id', $row->creator_telegram_user_id))
            ->map(fn ($row): array => [
                'telegram_user_id' => (string) $row->creator_telegram_user_id,
                'username' => $row->username,
                'name' => $row->name,
                'joins' => 0,
                'links' => (int) $row->links_total,
                'last_join_at' => null,
            ]);

        return $rows->concat($withoutJoins->values())->values()->all();
    }

    /**
     * Best known display name per Telegram id, taken from the links they created.
     *
     * @return array<int, array{username: string|null, name: string|null}>
     */
    protected function identities(): array
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
     * The most recent joins, each with the admin it is credited to.
     *
     * With a search term this becomes the lookup the panel is asked for most:
     * type a member's name, @username or numeric id and get back the admin
     * whose link let them in. An inviter's name matches too, which turns the
     * same box into "everyone this admin brought in".
     *
     * @return list<array{
     *     id: int,
     *     joiner: string,
     *     joiner_username: string|null,
     *     joiner_telegram_user_id: string,
     *     inviter: string|null,
     *     inviter_telegram_user_id: string|null,
     *     chat_title: string|null,
     *     source: string,
     *     joined_at: string|null,
     * }>
     */
    protected function recentJoins(?int $chatId, string $search = ''): array
    {
        $identities = $this->identities();

        return TelegramInviteLinkJoin::query()
            ->with('inviteLink:id,chat_title')
            ->when($chatId, fn ($query) => $query->where('chat_id', $chatId))
            ->when($search !== '', fn ($query) => $this->applySearch($query, $search, $this->matchingInviterIds($search)))
            ->latest('joined_at')
            ->limit(100)
            ->get()
            ->map(function (TelegramInviteLinkJoin $join) use ($identities): array {
                $inviterId = $join->creator_telegram_user_id;

                return [
                    'id' => $join->id,
                    'joiner' => $join->joiner_name ?: ($join->joiner_username ?? 'عضو'),
                    'joiner_username' => $join->joiner_username,
                    'joiner_telegram_user_id' => (string) $join->joiner_telegram_user_id,
                    'inviter' => $inviterId === null
                        ? null
                        : ($identities[$inviterId]['name'] ?? $identities[$inviterId]['username'] ?? null),
                    'inviter_username' => $inviterId === null ? null : ($identities[$inviterId]['username'] ?? null),
                    'inviter_telegram_user_id' => $inviterId === null ? null : (string) $inviterId,
                    'chat_title' => $join->inviteLink?->chat_title,
                    'source' => $join->source,
                    'joined_at' => $join->joined_at?->toISOString(),
                ];
            })
            ->all();
    }

    /**
     * Match a join by who joined — display name, @username or numeric id —
     * or by the admin credited with it.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TelegramInviteLinkJoin>  $query
     * @param  list<int>  $inviterIds
     */
    protected function applySearch(\Illuminate\Database\Eloquent\Builder $query, string $search, array $inviterIds): void
    {
        $needle = '%'.mb_strtolower(ltrim($search, '@')).'%';

        $query->where(function ($query) use ($needle, $inviterIds): void {
            $query->whereRaw('LOWER(joiner_username) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(joiner_name) LIKE ?', [$needle])
                ->orWhereRaw('CAST(joiner_telegram_user_id AS TEXT) LIKE ?', [$needle]);

            if ($inviterIds !== []) {
                $query->orWhereIn('creator_telegram_user_id', $inviterIds);
            }
        });
    }

    /**
     * Telegram ids of the admins whose own name, username or id matches the term.
     *
     * @return list<int>
     */
    protected function matchingInviterIds(string $search): array
    {
        $needle = '%'.mb_strtolower(ltrim($search, '@')).'%';

        return TelegramInviteLink::query()
            ->whereRaw('LOWER(creator_username) LIKE ?', [$needle])
            ->orWhereRaw('LOWER(creator_name) LIKE ?', [$needle])
            ->orWhereRaw('CAST(creator_telegram_user_id AS TEXT) LIKE ?', [$needle])
            ->distinct()
            ->pluck('creator_telegram_user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Every «رابط» use the command counter has ever seen, per requester.
     *
     * The counter predates join tracking and counts requests, not joins — it
     * also counts attempts that were refused for lack of permission, so the
     * "before tracking" figure is an upper bound: total uses minus the links
     * actually stored since the tracker went live.
     *
     * @return list<array{
     *     telegram_user_id: string,
     *     username: string|null,
     *     name: string|null,
     *     requests: int,
     *     before_tracking: int,
     *     last_used_at: string|null,
     * }>
     */
    protected function preTrackingRequests(?int $chatId): array
    {
        $identities = $this->identities();

        $tracked = TelegramInviteLink::query()
            ->selectRaw('creator_telegram_user_id, COUNT(*) as links_total')
            ->when($chatId, fn ($query) => $query->where('chat_id', $chatId))
            ->groupBy('creator_telegram_user_id')
            ->pluck('links_total', 'creator_telegram_user_id');

        return BotCommandStat::query()
            ->where('command_name', 'رابط')
            ->when($chatId, fn ($query) => $query->where('chat_id', $chatId))
            ->selectRaw('telegram_user_id, SUM(count) as requests, MAX(last_used_at) as last_used_at')
            ->groupBy('telegram_user_id')
            ->orderByDesc('requests')
            ->get()
            ->map(fn ($row): array => [
                'telegram_user_id' => (string) $row->telegram_user_id,
                'username' => $identities[(int) $row->telegram_user_id]['username'] ?? null,
                'name' => $identities[(int) $row->telegram_user_id]['name'] ?? null,
                'requests' => (int) $row->requests,
                'before_tracking' => max(0, (int) $row->requests - (int) ($tracked[$row->telegram_user_id] ?? 0)),
                'last_used_at' => $row->last_used_at ? Carbon::parse($row->last_used_at)->toISOString() : null,
            ])
            ->all();
    }
}
