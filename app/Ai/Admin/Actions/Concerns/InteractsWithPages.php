<?php

namespace App\Ai\Admin\Actions\Concerns;

use App\Ai\Admin\Actions\ActionResult;
use App\Models\Page;
use App\Models\PageChangeRequest;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Page helpers shared by the page actions: slug generation with parity to
 * {@see \App\Http\Controllers\Manage\PageController} and the review-aware
 * write that routes a review-mode author's edit into the pending
 * {@see PageChangeRequest} queue instead of the live page (mirroring the
 * review-aware half of PageController::update() and the old MCP UpdatePageTool).
 */
trait InteractsWithPages
{
    /**
     * Latin transliteration plus a numeric suffix on collision; trashed pages
     * count because the slug column is unique.
     */
    protected function generateUniqueSlug(string $title): string
    {
        $baseSlug = '/'.Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        while (Page::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Apply a validated partial page update — or, when the author is in review
     * mode, capture it into their single pending change request for this page
     * (merging with any fields already awaiting review) so the live page is
     * untouched until a reviewer approves it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function writeOrQueue(Page $page, array $data, User $user, string $appliedMessage): ActionResult
    {
        if ($user->mustHaveChangesReviewed()) {
            $pending = $page->changeRequests()
                ->where('author_id', $user->getKey())
                ->where('status', PageChangeRequest::STATUS_PENDING)
                ->first();

            $page->changeRequests()->updateOrCreate(
                ['id' => $pending?->id],
                [
                    'author_id' => $user->getKey(),
                    'status' => PageChangeRequest::STATUS_PENDING,
                    'payload' => array_merge($pending?->payload ?? [], $data),
                ],
            );

            return ActionResult::text('تم إرسال تعديلك للمراجعة، وسيظهر على الموقع بعد اعتماده من مراجع. Your edit was submitted for review and will publish once approved.');
        }

        $page->update($data);

        return ActionResult::text($appliedMessage);
    }
}
