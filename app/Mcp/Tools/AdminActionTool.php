<?php

namespace App\Mcp\Tools;

use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Exposes one {@see AdminAction} to the authenticated MCP server as an
 * immediate-write tool — the external-client counterpart of
 * {@see \App\Ai\Admin\Actions\AssistantActionTool}. The transport is already
 * OAuth-protected; this adds the per-tool ability gate ({@see AdminAction::requiredAbility()},
 * mirroring the panel's `can:` middleware) and runs the action straight
 * through (no proposal), so a read returns its content and a write applies
 * immediately, acting as the signed-in moderator.
 *
 * One adapter class wraps every action: the server registers configured
 * INSTANCES (not class-strings), each carrying its wrapped action, so a
 * capability is defined once and surfaces identically here and on the
 * in-app assistant.
 */
class AdminActionTool extends Tool
{
    public function __construct(private readonly AdminAction $action) {}

    public function name(): string
    {
        return $this->action->name();
    }

    public function description(): string
    {
        return $this->action->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->action->schema($schema);
    }

    /**
     * Surface the MCP `readOnlyHint` for read actions without a class attribute
     * (this single adapter wraps both read and write actions).
     *
     * @return array<string, mixed>
     */
    public function annotations(): array
    {
        return $this->action->isReadOnly() ? ['readOnlyHint' => true] : [];
    }

    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('يلزم تسجيل الدخول. Authentication required.');
        }

        $ability = $this->action->requiredAbility();

        if ($ability !== null && ! $user->can($ability)) {
            return Response::error('ليست لديك صلاحية تنفيذ هذا الإجراء. You are not authorized to perform this action.');
        }

        try {
            $result = $this->action->handle($request->all(), $user);
        } catch (AdminActionException $exception) {
            return Response::error('تعذّر تنفيذ الإجراء: '.$exception->getMessage());
        }

        return Response::text($result->message);
    }
}
