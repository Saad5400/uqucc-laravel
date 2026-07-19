<?php

namespace App\Ai\Admin\Actions\System;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;

/**
 * Flush the application cache, mirroring
 * {@see \App\Http\Controllers\Manage\CacheController::clear()}. It runs the
 * cache:clear Artisan command; the cache rebuilds lazily on upcoming visits.
 */
class ClearCacheAction extends AdminAction
{
    public function name(): string
    {
        return 'clear_cache';
    }

    public function category(): string
    {
        return 'system';
    }

    public function description(): string
    {
        return 'Flush the application cache; it rebuilds lazily on upcoming visits '
            .'(مسح ذاكرة التخزين المؤقت للتطبيق؛ يُعاد بناؤها تلقائيًا مع الزيارات القادمة). '
            .'Takes no parameters.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'مسح ذاكرة التخزين المؤقت للتطبيق.';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        Artisan::call('cache:clear');

        return ActionResult::text('تم مسح ذاكرة التخزين المؤقت للتطبيق.');
    }
}
