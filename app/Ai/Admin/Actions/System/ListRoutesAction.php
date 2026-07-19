<?php

namespace App\Ai\Admin\Actions\System;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/**
 * The application's own named routes — name, HTTP methods and URI — so the
 * assistant knows the real URL structure of the panel and public site instead
 * of guessing links. Framework/vendor routes (passport, sanctum, ignition,
 * telescope, horizon, the mcp/oauth endpoints, …) are filtered out. Read-only.
 */
class ListRoutesAction extends AdminAction
{
    /** Named-route prefixes that are framework/vendor noise, not app surface. */
    private const IGNORED_PREFIXES = [
        'passport.', 'sanctum.', 'ignition.', 'telescope.', 'horizon.',
        'l5-swagger.', 'livewire.', 'filament.', 'mcp.', 'oauth.',
    ];

    public function name(): string
    {
        return 'list_routes';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'system';
    }

    public function description(): string
    {
        return 'List the application\'s named routes (name, HTTP methods, URL) '
            .'(عرض مسارات الموقع المُسمّاة مع طرق الطلب والرابط). '
            .'Use it to reference the correct panel or public-site URLs. Optional filter matches the route name or URI. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()
                ->description('Optional substring to match against the route name or URI (e.g. "manage", "tutors", "pages").'),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $filter = trim((string) ($normalized['filter'] ?? ''));

        $lines = [];

        foreach (Route::getRoutes() as $route) {
            $routeName = $route->getName();

            if ($routeName === null || $this->isIgnored($routeName)) {
                continue;
            }

            $uri = $route->uri();
            $methods = $this->methods($route);

            if ($filter !== '' && ! str_contains($routeName, $filter) && ! str_contains($uri, $filter)) {
                continue;
            }

            $lines[$routeName] = sprintf('- %s | %s | /%s', $routeName, $methods, ltrim($uri, '/'));
        }

        if ($lines === []) {
            return ActionResult::text('لا توجد مسارات مطابقة.');
        }

        ksort($lines);

        return ActionResult::text(
            "مسارات الموقع المُسمّاة (الاسم | الطريقة | الرابط):\n".implode("\n", array_values($lines)),
        );
    }

    private function isIgnored(string $routeName): bool
    {
        foreach (self::IGNORED_PREFIXES as $prefix) {
            if (str_starts_with($routeName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function methods(RoutingRoute $route): string
    {
        $methods = array_values(array_filter(
            $route->methods(),
            static fn (string $method): bool => $method !== 'HEAD',
        ));

        return implode(',', $methods);
    }
}
