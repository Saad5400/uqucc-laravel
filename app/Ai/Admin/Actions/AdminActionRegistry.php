<?php

namespace App\Ai\Admin\Actions;

use App\Ai\Admin\Actions\Pages\GetPageContentAction;
use App\Ai\Admin\Actions\Pages\ListPagesAction;
use App\Ai\Admin\Actions\Pages\ManagePageStructureAction;
use App\Ai\Admin\Actions\Pages\RestorePageAction;
use App\Ai\Admin\Actions\Pages\UpdatePageAction;
use App\Ai\Admin\Actions\Pages\UpdatePageContentAction;
use App\Ai\Admin\Actions\Settings\GetSettingsAction;
use App\Ai\Admin\Actions\Settings\UpdateSettingAction;

/**
 * The single ordered list of unified admin capabilities. Both AI surfaces
 * build their toolset from here — the in-app assistant wraps each action in an
 * {@see AssistantActionTool}, the MCP server in an
 * {@see \App\Mcp\Tools\AdminActionTool} — so a capability is defined once and
 * appears on both surfaces with identical permissions and validation.
 */
class AdminActionRegistry
{
    /**
     * @var list<class-string<AdminAction>>
     */
    private const ACTIONS = [
        // Pages
        ListPagesAction::class,
        GetPageContentAction::class,
        ManagePageStructureAction::class,
        UpdatePageAction::class,
        UpdatePageContentAction::class,
        RestorePageAction::class,
        // Settings
        GetSettingsAction::class,
        UpdateSettingAction::class,
    ];

    /** @var array<string, AdminAction>|null */
    private ?array $resolved = null;

    /**
     * Every action, resolved from the container and keyed by tool name.
     *
     * @return array<string, AdminAction>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = [];

        foreach (self::ACTIONS as $class) {
            $action = app($class);
            $resolved[$action->name()] = $action;
        }

        return $this->resolved = $resolved;
    }

    public function get(string $name): ?AdminAction
    {
        return $this->all()[$name] ?? null;
    }
}
