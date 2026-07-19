<?php

namespace App\Ai\Admin\Actions\Analytics;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Models\Ai\AiUsage;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;

/**
 * The AI spend ledger as text: all-time cost, cost over a recent window, today's
 * spend, and the breakdown by feature (تكلفة الذكاء الاصطناعي: الإجمالي، وآخر
 * فترة، واليوم، والتوزيع حسب الميزة). Sourced from {@see AiUsage}, the
 * append-only per-call cost rows. Read-only.
 */
class GetAiUsageAction extends AdminAction
{
    /** Default window, in days, for the recent-spend figure. */
    private const DEFAULT_DAYS = 30;

    /** Upper bound for the requested window so a stray value stays sane. */
    private const MAX_DAYS = 365;

    /** Decimals used when rendering USD amounts. */
    private const MONEY_DECIMALS = 4;

    public function name(): string
    {
        return 'get_ai_usage';
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
        return 'Get the AI spend summary in USD: all-time cost, cost over the last N days (default 30), today\'s '
            .'spend, and the cost broken down by feature (ملخّص تكلفة الذكاء الاصطناعي بالدولار: الإجمالي، وتكلفة '
            .'آخر فترة، وإنفاق اليوم، والتوزيع حسب الميزة). Optional days sets the recent window. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()
                ->description('Optional window in days for the recent-spend figure (default 30, capped at 365).'),
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

        $totalAllTime = (float) AiUsage::query()->sum('cost');
        $totalWindow = (float) AiUsage::query()->where('created_at', '>=', now()->subDays($days))->sum('cost');
        $totalToday = (float) AiUsage::query()->where('created_at', '>=', now()->startOfDay())->sum('cost');

        if ($totalAllTime === 0.0 && AiUsage::query()->doesntExist()) {
            return ActionResult::text('لا توجد بيانات استخدام للذكاء الاصطناعي بعد.');
        }

        $lines = [
            'تكلفة الذكاء الاصطناعي (بالدولار الأمريكي):',
            '- الإجمالي (كل الأوقات): '.$this->money($totalAllTime).' $.',
            '- آخر '.$days.' يوماً: '.$this->money($totalWindow).' $.',
            '- اليوم: '.$this->money($totalToday).' $.',
            '',
            'التوزيع حسب الميزة (كل الأوقات):',
            ...$this->byFeatureLines(),
        ];

        return ActionResult::text(implode("\n", $lines));
    }

    /**
     * @return list<string>
     */
    private function byFeatureLines(): array
    {
        /** @var Collection<int, \App\Models\Ai\AiUsage> $rows */
        $rows = AiUsage::query()
            ->select('feature')
            ->selectRaw('SUM(cost) as total_cost')
            ->groupBy('feature')
            ->orderByDesc('total_cost')
            ->get();

        if ($rows->isEmpty()) {
            return ['- لا توجد بيانات بعد.'];
        }

        return $rows
            ->map(fn (AiUsage $row): string => sprintf('- %s: %s $.', $row->feature, $this->money((float) $row->getAttribute('total_cost'))))
            ->all();
    }

    /**
     * Render a USD amount with fixed decimals and tabular clarity.
     */
    private function money(float $amount): string
    {
        return number_format($amount, self::MONEY_DECIMALS, '.', '');
    }
}
