<?php

namespace App\Ai\Admin\Actions\Analytics;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Models\BotCommandStat;
use App\Models\Page;
use App\Models\PageViewStat;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;

/**
 * The manage dashboard's headline analytics as text: total and unique page
 * views over a recent window, bot command uses, the busiest command, the
 * contributor count, plus the most-viewed pages and top bot commands
 * (نظرة عامة على إحصاءات الموقع: المشاهدات والزوّار واستخدام البوت والصفحات
 * الأكثر مشاهدة وأكثر الأوامر). Read-only. Mirrors the computations in
 * {@see \App\Http\Controllers\Manage\DashboardController}.
 */
class GetAnalyticsAction extends AdminAction
{
    /** Default window, in days, for the view/command aggregates. */
    private const DEFAULT_DAYS = 30;

    /** Upper bound for the requested window so a stray value stays sane. */
    private const MAX_DAYS = 365;

    /** How many rows to list for most-viewed pages and top commands. */
    private const TOP_LIMIT = 10;

    public function name(): string
    {
        return 'get_analytics';
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
        return 'Get the site analytics summary: total and unique page views, bot command uses and the busiest '
            .'command over the last N days (default 30), the contributor count, the most-viewed pages and the top '
            .'bot commands (ملخّص إحصاءات الموقع: المشاهدات والزوّار الفريدون واستخدام البوت والصفحات الأكثر مشاهدة '
            .'وأكثر أوامر البوت استخداماً). Optional days sets the window. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()
                ->description('Optional window in days for the view/command aggregates (default 30, capped at 365).'),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $days = (int) ($input['days'] ?? self::DEFAULT_DAYS);

        if ($days < 1) {
            $days = self::DEFAULT_DAYS;
        }

        return ['days' => min($days, self::MAX_DAYS)];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $days = (int) $normalized['days'];
        $since = now()->subDays($days);

        $views = (int) PageViewStat::query()->where('last_viewed_at', '>=', $since)->sum('view_count');
        $uniqueVisitors = PageViewStat::query()
            ->whereNotNull('ip_address')
            ->where('last_viewed_at', '>=', $since)
            ->distinct()
            ->count('ip_address');
        $botUses = (int) BotCommandStat::query()->where('last_used_at', '>=', $since)->sum('count');
        $contributors = DB::table('page_user')->distinct()->count('user_id');

        $topCommand = BotCommandStat::query()
            ->select('command_name')
            ->selectRaw('SUM(count) as total_uses')
            ->groupBy('command_name')
            ->orderByDesc('total_uses')
            ->first();

        $lines = [
            'إحصاءات الموقع (آخر '.$days.' يوماً):',
            '- إجمالي المشاهدات: '.$views.'.',
            '- الزوّار الفريدون: '.$uniqueVisitors.'.',
            '- استخدام البوت: '.$botUses.'.',
            '- الأمر الأكثر استخداماً: '.($topCommand === null
                ? '—'
                : $topCommand->command_name.' ('.(int) $topCommand->total_uses.' مرة).'),
            '- المساهمون في الصفحات: '.$contributors.'.',
            '',
            'الصفحات الأكثر مشاهدة (كل الأوقات):',
            ...$this->mostViewedLines(),
            '',
            'أكثر أوامر البوت استخداماً (كل الأوقات):',
            ...$this->topCommandLines(),
        ];

        return ActionResult::text(implode("\n", $lines));
    }

    /**
     * @return list<string>
     */
    private function mostViewedLines(): array
    {
        $pages = Page::query()
            ->join('page_view_stats', 'pages.id', '=', 'page_view_stats.page_id')
            ->groupBy('pages.id', 'pages.title')
            ->orderByDesc('total_views')
            ->limit(self::TOP_LIMIT)
            ->get(['pages.id', 'pages.title', DB::raw('SUM(page_view_stats.view_count) as total_views')]);

        if ($pages->isEmpty()) {
            return ['- لا توجد بيانات بعد.'];
        }

        return $pages
            ->map(fn (Page $page): string => sprintf('- %s: %d مشاهدة.', $page->title, (int) $page->total_views))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function topCommandLines(): array
    {
        $commands = BotCommandStat::query()
            ->select('command_name')
            ->selectRaw('SUM(count) as total_uses')
            ->groupBy('command_name')
            ->orderByDesc('total_uses')
            ->limit(self::TOP_LIMIT)
            ->get();

        if ($commands->isEmpty()) {
            return ['- لا توجد بيانات بعد.'];
        }

        return $commands
            ->map(fn (BotCommandStat $stat): string => sprintf('- %s: %d مرة.', $stat->command_name, (int) $stat->total_uses))
            ->all();
    }
}
