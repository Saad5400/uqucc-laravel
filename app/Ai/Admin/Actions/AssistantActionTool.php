<?php

namespace App\Ai\Admin\Actions;

use App\Ai\Admin\Tools\Concerns\GatedByAdminAssistant;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Classified\Capability;
use Saad\AiKit\Approvals\Classified\ClassifiedTool;
use Stringable;

/**
 * Exposes one {@see AdminAction} to the in-app admin assistant on ai-kit's
 * classified approval seam (laravel/ai native `Approvable`). A READ runs
 * immediately; a WRITE pauses the turn — the admin sees an approval card
 * (editable form for payload writes, one-click for destructive) and their
 * decision resumes the SAME tool call, so what was previewed is exactly
 * what executes. uqucc runs without an undo ledger, so even undoable
 * writes take the narrower tier and pause.
 *
 * Validation runs twice, mirroring the old propose/confirm split: once at
 * pause time to produce the card's Arabic summary (invalid input skips the
 * pause entirely and the model reads the validation error instead), and
 * again inside {@see perform()} at execution time — which also covers
 * user-edited arguments, since an edit travels the same schema-validated
 * tool path.
 */
class AssistantActionTool extends ClassifiedTool
{
    use GatedByAdminAssistant;

    public function __construct(private readonly AdminAction $action) {}

    public function name(): string
    {
        return $this->action->name();
    }

    public function description(): Stringable|string
    {
        return $this->action->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->action->schema($schema);
    }

    /**
     * The card's form schema, from the action's own
     * {@see AdminAction::fieldWidgets()}. Everything an action leaves out is
     * inferred by the kit from the pending value.
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return $this->action->fieldWidgets();
    }

    public function capability(): Capability
    {
        return match (true) {
            $this->action->isReadOnly() => Capability::read(),
            $this->action->isDestructive() => Capability::destructive(),
            default => Capability::write(),
        };
    }

    /**
     * Pause only calls that could actually execute: when the kill switch is
     * engaged, the admin lacks the ability, or the arguments fail validation,
     * the call runs straight into {@see perform()}'s refusal — the model
     * reads the Arabic reason immediately instead of parking a doomed card
     * on the admin's screen.
     */
    protected function needsApproval(Request $request): Approval|bool
    {
        if (parent::needsApproval($request) === false) {
            return false;
        }

        $user = Auth::user();

        if ($this->adminAssistantIsDisabled() || ! $this->allowed($user)) {
            return false;
        }

        try {
            $normalized = $this->action->validate($request->all(), $user);
        } catch (AdminActionException) {
            return false;
        }

        return Approval::required($this->action->summarize($normalized, $user) ?: null);
    }

    protected function perform(Request $request): Stringable|string
    {
        if ($this->adminAssistantIsDisabled()) {
            return $this->adminAssistantDisabledReply();
        }

        $user = Auth::user();

        if (! $this->allowed($user)) {
            return 'لا تملك صلاحية تنفيذ هذا الإجراء.';
        }

        try {
            return $this->action->handle($request->all(), $user)->message;
        } catch (AdminActionException $exception) {
            return 'تعذّر تنفيذ الإجراء: '.$exception->getMessage();
        }
    }

    public function title(Request $request): string
    {
        return str_replace('_', ' ', $this->action->name());
    }

    protected function executedBy(): ?string
    {
        return Auth::id() !== null ? 'admin:'.Auth::id() : null;
    }

    /**
     * @phpstan-assert-if-true User $user
     */
    private function allowed(?object $user): bool
    {
        $ability = $this->action->requiredAbility();

        return $user instanceof User && ($ability === null || $user->can($ability));
    }
}
