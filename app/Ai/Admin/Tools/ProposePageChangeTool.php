<?php

namespace App\Ai\Admin\Tools;

use App\Ai\Admin\InvalidProposalException;
use App\Ai\Admin\PageChangeRules;
use App\Ai\Admin\Tools\Concerns\GatedByAdminAssistant;
use App\Models\Ai\AdminPendingAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Phase one of the admin assistant's write path for pages: validates the
 * requested change and persists it as a PENDING action — it NEVER touches a
 * page itself. The change only happens when the admin presses تأكيد on the
 * action card, which routes through {@see \App\Ai\Admin\ProposalExecutor}.
 */
class ProposePageChangeTool implements Tool
{
    use GatedByAdminAssistant;

    public function __construct(private readonly PageChangeRules $rules) {}

    public function description(): Stringable|string
    {
        return 'Propose ONE change to the site\'s page tree (اقتراح تعديل واحد على شجرة صفحات الموقع). '
            .'Actions: create (new page), rename, move (to a new parent), reorder (siblings of one parent), '
            .'publish, unpublish, delete. The change is NOT applied: it is saved as a pending proposal the '
            .'admin must confirm in the UI. Use list_pages first to get correct page ids. '
            .'Returns the proposal id and its human summary.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->adminAssistantIsDisabled()) {
            return $this->adminAssistantDisabledReply();
        }

        try {
            $payload = $this->rules->validate($request->all());
        } catch (InvalidProposalException $exception) {
            return 'تعذر إنشاء الاقتراح: '.$exception->getMessage();
        }

        $summary = $this->rules->summarize($payload);

        $proposal = AdminPendingAction::query()->create([
            'type' => AdminPendingAction::TYPE_PAGE_CHANGE,
            'payload' => $payload,
            'summary' => $summary,
            'status' => AdminPendingAction::STATUS_PENDING,
            'proposed_by' => (int) Auth::id(),
        ]);

        return "تم إنشاء اقتراح بانتظار تأكيد المشرف — لم يُنفَّذ بعد.\n"
            ."الملخص: {$summary}\n"
            ."---\nproposal_id: {$proposal->id}";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('The kind of change to propose.')
                ->enum(PageChangeRules::ACTIONS)
                ->required(),
            'page_id' => $schema->integer()
                ->description('The target page id (from list_pages). Required for rename, move, publish, unpublish, delete.'),
            'title' => $schema->string()
                ->description('The page title: the new page\'s title for create, or the new title for rename.'),
            'parent_id' => $schema->integer()
                ->description('The parent page id. For create: where the new page goes (omit for root level). For move: the new parent (omit to move to root level).'),
            'ids' => $schema->array()
                ->description('For reorder only: ALL sibling page ids of one parent, in the desired new order.')
                ->items($schema->integer()),
        ];
    }
}
