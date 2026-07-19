<?php

namespace App\Ai\Admin\Actions;

use App\Ai\Admin\Tools\Concerns\GatedByAdminAssistant;
use App\Models\Ai\AdminPendingAction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Exposes one {@see AdminAction} to the in-app admin assistant as a laravel/ai
 * tool. A READ runs immediately; a WRITE is confirm-gated — it validates and
 * persists a pending {@see AdminPendingAction} (never touching live state) and
 * returns the stable `proposal_id:` trailer the {@see \App\Ai\Admin\ProposalExtractor}
 * turns into an action card. Confirming the card runs the SAME action through
 * {@see \App\Ai\Admin\ProposalExecutor}.
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
            $normalized = $this->action->validate($input, $user);
        } catch (AdminActionException $exception) {
            return 'تعذر إنشاء الاقتراح: '.$exception->getMessage();
        }

        $summary = $this->action->summarize($normalized, $user);

        $proposal = AdminPendingAction::query()->create([
            'type' => $this->action->name(),
            'payload' => [
                'action' => $this->action->name(),
                'category' => $this->action->category(),
                'input' => $input,
                'preview' => $normalized,
            ],
            'summary' => $summary,
            'status' => AdminPendingAction::STATUS_PENDING,
            'proposed_by' => (int) Auth::id(),
        ]);

        return "تم إنشاء اقتراح بانتظار تأكيد المشرف — لم يُنفَّذ بعد.\n"
            ."الملخص: {$summary}\n"
            ."---\nproposal_id: {$proposal->id}";
    }
}
