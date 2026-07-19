<?php

namespace App\Ai\Admin\Actions;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * ONE admin capability, defined once and exposed on every AI surface. It is
 * the single source of truth the "unify the assistant and the MCP server"
 * work is built on: each surface only wraps an action in an adapter and
 * differs in write mode.
 *
 *   - The MCP server ({@see \App\Mcp\Tools\AdminActionTool}) wraps it as an
 *     immediate-write tool, gated by {@see requiredAbility()} over OAuth.
 *   - The in-app assistant ({@see \App\Ai\Admin\Actions\AssistantActionTool})
 *     wraps a WRITE as a confirm-gated proposal (an {@see \App\Models\Ai\AdminPendingAction}
 *     a human confirms; {@see \App\Ai\Admin\ProposalExecutor} then calls
 *     {@see handle()}), and a READ as an immediate call.
 *
 * Contract for writes: {@see validate()} normalizes raw model input against
 * live state (throwing {@see AdminActionException} with an Arabic reason on
 * any problem), {@see summarize()} describes the normalized change for the
 * card, and {@see run()} performs it through Eloquent so model events and the
 * Page::booted() cache flushes keep firing (frozen contract — never DB:: writes).
 */
abstract class AdminAction
{
    /** The canonical snake_case tool name, identical on both surfaces. */
    abstract public function name(): string;

    /** Bilingual (English + Arabic) description shown to the model. */
    abstract public function description(): string;

    /**
     * The tool's JSON-schema parameters. The signature is shared by both
     * frameworks (laravel/ai and laravel/mcp both pass this JsonSchema).
     *
     * @return array<string, Type>
     */
    abstract public function schema(JsonSchema $schema): array;

    /**
     * The Spatie ability/gate the signed-in user must hold, or null for an
     * unguarded action. Enforced identically by both adapters, mirroring the
     * `can:` middleware on the matching /manage route.
     */
    public function requiredAbility(): ?string
    {
        return null;
    }

    /**
     * A read-only action runs immediately on both surfaces (no proposal) and
     * carries the MCP `readOnlyHint`.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * Grouping key used for the assistant's action-card icon/label:
     * pages | reviews | tutors | users | settings | analytics | corpus |
     * telegram | system.
     */
    public function category(): string
    {
        return 'system';
    }

    /**
     * Validate and normalize raw model arguments against the live state.
     * Returns the normalized payload (ids resolved, values cast, display
     * names attached) or throws {@see AdminActionException} with an Arabic
     * reason. The default passes input through unchanged (fine for reads).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        return $input;
    }

    /**
     * The Arabic one-line summary of a normalized WRITE payload — what the
     * admin reads on the action card before pressing تأكيد. Reads return ''.
     *
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return '';
    }

    /**
     * Perform the action on already-normalized input. Reads put their rendered
     * content in the result message; writes perform the Eloquent write and
     * return a confirmation line.
     *
     * @param  array<string, mixed>  $normalized
     */
    abstract protected function run(array $normalized, User $user): ActionResult;

    /**
     * Validate then run — the immediate execution path used by the MCP
     * adapter, by read actions on the assistant, and by the executor when a
     * proposal is confirmed (re-validating against current state).
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input, User $user): ActionResult
    {
        return $this->run($this->validate($input, $user), $user);
    }
}
