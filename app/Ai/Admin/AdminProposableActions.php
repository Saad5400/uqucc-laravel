<?php

namespace App\Ai\Admin;

use App\Ai\Admin\Actions\AdminActionRegistry;
use LogicException;
use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Approvals\Contracts\ProposableAction;

/**
 * ai-kit's {@see ActionRegistry} backed by the app's own
 * {@see AdminActionRegistry}, so the proposal flow resolves the SAME unified
 * actions the MCP server exposes — defined once, wrapped lazily, with reads
 * invisible to the proposal flow (a read never needs confirming).
 */
class AdminProposableActions implements ActionRegistry
{
    public function __construct(private readonly AdminActionRegistry $actions) {}

    public function get(string $type): ?ProposableAction
    {
        $action = $this->actions->get($type);

        return $action === null || $action->isReadOnly()
            ? null
            : new ProposableAdminAction($action);
    }

    public function register(ProposableAction|string ...$actions): void
    {
        throw new LogicException('uqucc actions are defined once in AdminActionRegistry — add them there.');
    }
}
