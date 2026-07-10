<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\BotCommandStat;
use App\Models\Page;
use App\Models\PageViewStat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The manage panel overview: immediate headline stats plus deferred
     * charts and lists so the page paints fast and fills in as data loads.
     */
    public function index(): Response
    {
        return Inertia::render('manage/Dashboard', [
            'stats' => $this->overviewStats(),
            'viewsChart' => Inertia::defer(fn (): array => $this->dailySeries(PageViewStat::query(), 'last_viewed_at', 'view_count')),
            'commandsChart' => Inertia::defer(fn (): array => $this->dailySeries(BotCommandStat::query(), 'last_used_at', 'count')),
            'latestPages' => Inertia::defer(fn (): array => $this->latestPages()),
            'mostViewed' => Inertia::defer(fn (): array => $this->mostViewedPages()),
            'topCommands' => Inertia::defer(fn (): array => $this->topCommands()),
        ]);
    }

    /**
     * @return array{
     *     totalPages: int,
     *     rootPages: int,
     *     contributors: int,
     *     views30d: int,
     *     uniqueVisitors30d: int,
     *     botUses30d: int,
     *     topCommand: array{name: string, uses: int}|null,
     * }
     */
    private function overviewStats(): array
    {
        $since = now()->subDays(30);

        $topCommand = BotCommandStat::query()
            ->select('command_name')
            ->selectRaw('SUM(count) as total_uses')
            ->groupBy('command_name')
            ->orderByDesc('total_uses')
            ->first();

        return [
            'totalPages' => Page::query()->count(),
            'rootPages' => Page::query()->whereNull('parent_id')->count(),
            'contributors' => DB::table('page_user')->distinct()->count('user_id'),
            'views30d' => (int) PageViewStat::query()->where('last_viewed_at', '>=', $since)->sum('view_count'),
            'uniqueVisitors30d' => PageViewStat::query()
                ->whereNotNull('ip_address')
                ->where('last_viewed_at', '>=', $since)
                ->distinct()
                ->count('ip_address'),
            'botUses30d' => (int) BotCommandStat::query()->where('last_used_at', '>=', $since)->sum('count'),
            'topCommand' => $topCommand === null ? null : [
                'name' => $topCommand->command_name,
                'uses' => (int) $topCommand->total_uses,
            ],
        ];
    }

    /**
     * One point per day for the last 30 days, zero-filled for silent days.
     *
     * @return list<array{date: string, count: int}>
     */
    private function dailySeries(Builder $query, string $dateColumn, string $sumColumn): array
    {
        $start = now()->subDays(29)->startOfDay();

        $totalsByDay = $query
            ->where($dateColumn, '>=', $start)
            ->selectRaw("DATE({$dateColumn}) as day")
            ->selectRaw("SUM({$sumColumn}) as total")
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(range(29, 0))
            ->map(function (int $daysAgo) use ($totalsByDay): array {
                $date = now()->subDays($daysAgo)->toDateString();

                return ['date' => $date, 'count' => (int) ($totalsByDay[$date] ?? 0)];
            })
            ->all();
    }

    /**
     * @return list<array{id: int, title: string, updated_at: string|null}>
     */
    private function latestPages(): array
    {
        return Page::query()
            ->latest('updated_at')
            ->limit(10)
            ->get(['id', 'title', 'updated_at'])
            ->map(fn (Page $page): array => [
                'id' => $page->id,
                'title' => $page->title,
                'updated_at' => $page->updated_at?->toISOString(),
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, title: string, views: int}>
     */
    private function mostViewedPages(): array
    {
        return Page::query()
            ->join('page_view_stats', 'pages.id', '=', 'page_view_stats.page_id')
            ->groupBy('pages.id', 'pages.title')
            ->orderByDesc('total_views')
            ->limit(10)
            ->get(['pages.id', 'pages.title', DB::raw('SUM(page_view_stats.view_count) as total_views')])
            ->map(fn (Page $page): array => [
                'id' => $page->id,
                'title' => $page->title,
                'views' => (int) $page->total_views,
            ])
            ->all();
    }

    /**
     * @return list<array{command: string, uses: int}>
     */
    private function topCommands(): array
    {
        return BotCommandStat::query()
            ->select('command_name')
            ->selectRaw('SUM(count) as total_uses')
            ->groupBy('command_name')
            ->orderByDesc('total_uses')
            ->limit(10)
            ->get()
            ->map(fn (BotCommandStat $stat): array => [
                'command' => $stat->command_name,
                'uses' => (int) $stat->total_uses,
            ])
            ->all();
    }
}
