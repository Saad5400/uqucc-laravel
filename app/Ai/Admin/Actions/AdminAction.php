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
 *     wraps a WRITE as a confirm-gated pause on ai-kit's classified seam:
 *     the tool pauses the turn, the admin decides on a card, and their
 *     decision resumes the SAME call, re-validating before {@see run()}. A
 *     READ is an immediate call.
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

    /**
     * The English description shown to the model. Model-facing strings (name,
     * description, schema property names and their descriptions) stay English —
     * Arabic degrades tool calling; Arabic belongs in what humans read.
     */
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
     * A read-only action runs immediately on both surfaces (no approval) and
     * carries the MCP `readOnlyHint`.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * A destructive write discards something that cannot be recovered from
     * inside the app (hard deletes, wiped history). On the assistant it
     * renders as a one-click approval card — never an editable form — and
     * with an undo ledger it would still always pause. Server-derived: the
     * model never influences this classification.
     */
    public function isDestructive(): bool
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
     * How the assistant's approval card should render this action's
     * arguments, keyed by argument name — a kit `Field`, a bare
     * `FieldWidget`, or a partial spec array, forwarded by
     * {@see AssistantActionTool::fields()}.
     *
     * Declare only what the kit gets wrong. It already infers booleans,
     * numbers, arrays and long text from the pending value, and makes any
     * `id` / `*_id` argument readonly because it addresses the record the
     * write lands on. What still needs declaring is a long body the model
     * happened to send short (a markdown editor is not a text box once it
     * grows) and an argument that identifies the operation rather than its
     * payload — those go readonly, and the kit restores the original value
     * server-side if a client sends one back edited.
     *
     * @return array<string, mixed>
     */
    public function fieldWidgets(): array
    {
        return [];
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
     * adapter and by read actions on the assistant.
     *
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input, User $user): ActionResult
    {
        return $this->run($this->validate($input, $user), $user);
    }

    /**
     * Run on ALREADY-normalized input without re-validating — the confirm
     * phase of the proposal flow, where ai-kit's ProposalExecutor called
     * {@see validate()} against the current state moments before.
     *
     * @param  array<string, mixed>  $normalized
     */
    public function execute(array $normalized, User $user): ActionResult
    {
        return $this->run($normalized, $user);
    }
}
