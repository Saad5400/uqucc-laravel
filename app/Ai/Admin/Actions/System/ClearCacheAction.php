<?php

namespace App\Ai\Admin\Actions\System;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Http\Controllers\Manage\CacheController;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Artisan;

/**
 * Flush the application cache, mirroring
 * {@see CacheController::clear()}. It runs the
 * cache:clear Artisan command; the cache rebuilds lazily on upcoming visits.
 */
class ClearCacheAction extends AdminAction
{
    public function name(): string
    {
        return 'clear_cache';
    }

    /** Admin-only, like the /manage settings routes (`can:manage-settings`). */
    public function requiredAbility(): ?string
    {
        return 'manage-settings';
    }

    public function category(): string
    {
        return 'system';
    }

    public function description(): string
    {
        return 'Flush the application cache; it rebuilds lazily on upcoming visits. '
            .'Takes no parameters.';
    }

    /**
     * @return array<string, Type>
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
