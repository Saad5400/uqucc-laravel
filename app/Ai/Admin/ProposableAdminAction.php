<?php

namespace App\Ai\Admin;

use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\User;
use Saad\AiKit\Approvals\Contracts\ProposableAction;
use Saad\AiKit\Approvals\Exceptions\ActionValidationException;

/**
 * Presents one {@see AdminAction} to ai-kit's proposal flow. The kit executor
 * speaks {@see ProposableAction} (untyped actor, ActionValidationException);
 * the app's actions speak User + AdminActionException — this adapter maps
 * between them so a unified action stays defined once.
 *
 * The actor must resolve to the proposing admin: the controller passes
 * `User::find(proposed_by)` at confirm time, and a proposer who has since
 * been deleted fails validation with the same Arabic reason the old executor
 * produced.
 */
class ProposableAdminAction implements ProposableAction
{
    public function __construct(private readonly AdminAction $action) {}

    public function type(): string
    {
        return $this->action->name();
    }

    public function category(): string
    {
        return $this->action->category();
    }

    /**
     * AdminAction carries no destructive flag yet — nothing in this app uses
     * the kit's write gate or typed confirm, so `false` engages nothing.
     */
    public function destructive(): bool
    {
        return false;
    }

    public function undoable(): bool
    {
        return false;
    }

    public function typedConfirmPhrase(array $input, mixed $actor): ?string
    {
        return null;
    }

    public function validate(array $input, mixed $actor): array
    {
        try {
            return $this->action->validate($input, $this->user($actor));
        } catch (AdminActionException $exception) {
            throw new ActionValidationException($exception->getMessage(), previous: $exception);
        }
    }

    public function summarize(array $normalized, mixed $actor): string
    {
        return $this->action->summarize($normalized, $this->user($actor));
    }

    public function execute(array $input, mixed $actor): mixed
    {
        try {
            return $this->action->execute($input, $this->user($actor));
        } catch (AdminActionException $exception) {
            throw new ActionValidationException($exception->getMessage(), previous: $exception);
        }
    }

    private function user(mixed $actor): User
    {
        return $actor instanceof User
            ? $actor
            : throw new ActionValidationException('صاحب الاقتراح لم يعد موجوداً.');
    }
}
