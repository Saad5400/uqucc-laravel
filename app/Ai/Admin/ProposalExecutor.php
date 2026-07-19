<?php

namespace App\Ai\Admin;

use App\Ai\Admin\Actions\AdminActionException;
use App\Ai\Admin\Actions\AdminActionRegistry;
use App\Models\Ai\AdminPendingAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Phase two of the admin assistant's write path: applies a PENDING proposal
 * after the admin pressed تأكيد. The proposal's `type` is the unified action
 * name; the stored raw input is re-validated against the CURRENT state by the
 * action itself (the tree or settings may have changed since it was proposed)
 * and applied inside a transaction. Every write runs through the same
 * {@see \App\Ai\Admin\Actions\AdminAction} the MCP server uses, acting as the
 * proposer, so page writes go through Eloquent and the Page::booted() cache
 * flushes fire.
 */
class ProposalExecutor
{
    public function __construct(private readonly AdminActionRegistry $registry) {}

    /**
     * Apply the proposal. On success the action becomes `confirmed`; any
     * failure (unknown action, stale ids, type mismatch, database error)
     * marks it `failed` with the error surfaced for the action card.
     */
    public function confirm(AdminPendingAction $proposal): AdminPendingAction
    {
        if (! $proposal->isPending()) {
            return $proposal;
        }

        try {
            DB::transaction(function () use ($proposal): void {
                $action = $this->registry->get($proposal->type);

                if ($action === null) {
                    throw new AdminActionException('نوع الاقتراح غير معروف أو لم يعد مدعوماً.');
                }

                $user = User::query()->find($proposal->proposed_by);

                if ($user === null) {
                    throw new AdminActionException('صاحب الاقتراح لم يعد موجوداً.');
                }

                /** @var array<string, mixed> $input */
                $input = $proposal->payload['input'] ?? [];

                $action->handle($input, $user);

                $proposal->update([
                    'status' => AdminPendingAction::STATUS_CONFIRMED,
                    'executed_at' => now(),
                    'error' => null,
                ]);
            });
        } catch (AdminActionException $exception) {
            $proposal->update([
                'status' => AdminPendingAction::STATUS_FAILED,
                'error' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $proposal->update([
                'status' => AdminPendingAction::STATUS_FAILED,
                'error' => 'حدث خطأ غير متوقع أثناء تنفيذ الاقتراح.',
            ]);
        }

        return $proposal->refresh();
    }

    /**
     * Decline the proposal; nothing is applied.
     */
    public function reject(AdminPendingAction $proposal): AdminPendingAction
    {
        if ($proposal->isPending()) {
            $proposal->update(['status' => AdminPendingAction::STATUS_REJECTED]);
        }

        return $proposal->refresh();
    }
}
