<?php

namespace App\Ai\Admin\Actions;

use App\Ai\Admin\Tools\Concerns\GatedByAdminAssistant;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Saad\AiKit\Approvals\Exceptions\ActionValidationException;
use Saad\AiKit\Approvals\ProposalExecutor;
use Saad\AiKit\Approvals\ProposalTrailer;
use Stringable;

/**
 * Exposes one {@see AdminAction} to the in-app admin assistant as a laravel/ai
 * tool. A READ runs immediately; a WRITE is confirm-gated — ai-kit's
 * {@see ProposalExecutor} validates and persists a pending proposal (never
 * touching live state) and the reply carries the stable `proposal_id:`
 * trailer {@see ProposalTrailer} turns into an action card. Confirming the
 * card runs the SAME action through the executor's confirm phase.
 */
class AssistantActionTool implements Tool
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

    public function handle(Request $request): Stringable|string
    {
        if ($this->adminAssistantIsDisabled()) {
            return $this->adminAssistantDisabledReply();
        }

        $user = Auth::user();
        $ability = $this->action->requiredAbility();

        if (! $user instanceof User || ($ability !== null && ! $user->can($ability))) {
            return 'لا تملك صلاحية تنفيذ هذا الإجراء.';
        }

        $input = $request->all();

        if ($this->action->isReadOnly()) {
            try {
                return $this->action->handle($input, $user)->message;
            } catch (AdminActionException $exception) {
                return 'تعذّر تنفيذ الإجراء: '.$exception->getMessage();
            }
        }

        try {
            $proposal = app(ProposalExecutor::class)->propose(
                $this->action->name(),
                $input,
                $user,
                (string) $user->getKey(),
            );
        } catch (ActionValidationException $exception) {
            return 'تعذر إنشاء الاقتراح: '.$exception->getMessage();
        }

        return ProposalTrailer::render(
            "تم إنشاء اقتراح بانتظار تأكيد المشرف — لم يُنفَّذ بعد.\n"
            ."الملخص: {$proposal->summary}",
            $proposal,
        );
    }
}
