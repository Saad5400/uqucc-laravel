<?php

namespace App\Mcp\Tools\Moderation;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base for every tool on the authenticated {@see \App\Mcp\Servers\UqccAdminServer}.
 *
 * The server route is already OAuth-protected (`auth:api`), so a request only
 * reaches here with a signed-in user; this base adds the second gate — the
 * per-tool ability check — mirroring the `can:` middleware the `/manage`
 * panel routes use. Concrete tools implement {@see perform()} (called only
 * once authorized) and {@see requiredAbility()}.
 */
abstract class ModerationTool extends Tool
{
    /**
     * The ability the signed-in moderator must hold to run this tool, checked
     * through the app's gates/permissions exactly as the panel does.
     */
    abstract protected function requiredAbility(): string;

    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->can($this->requiredAbility())) {
            return Response::error('ليست لديك صلاحية تنفيذ هذا الإجراء. You are not authorized to perform this action.');
        }

        return $this->perform($request, $user);
    }

    abstract protected function perform(Request $request, User $user): Response;

    /**
     * Validate the tool arguments against the same rules the `/manage` panel
     * uses, reusing its Arabic messages. Returns the validated data, or a
     * ready-to-return error {@see Response} carrying the first failure so a
     * tool can `if ($x instanceof Response) return $x;`.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed>|Response
     */
    protected function validateInput(Request $request, array $rules, array $messages = []): array|Response
    {
        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return Response::error('تعذّر تنفيذ الإجراء: '.$validator->errors()->first());
        }

        return $validator->validated();
    }
}
