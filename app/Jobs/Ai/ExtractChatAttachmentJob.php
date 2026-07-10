<?php

namespace App\Jobs\Ai;

use App\Ai\Chat\ChatAttachmentTextExtractor;
use App\Models\Ai\ChatAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queued extraction of one chat attachment, dispatched on upload: extract
 * markdown (PDF text layer or vision) → store it on the row so the visitor's
 * next message can inject it as conversation context. Unlike the corpus
 * sibling ({@see ExtractCorpusDocumentJob}) it never ingests anything into
 * the public corpus — the text stays private to the owning session.
 *
 * Runs on the dedicated "ai" queue. NOT auto-retried (tries = 1): failure
 * lands on the row as status "failed" + an Arabic message the assistant can
 * relay; the visitor simply re-uploads. Reported, not rethrown.
 */
class ExtractChatAttachmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly string $attachmentId)
    {
        $this->onQueue('ai');
    }

    public function handle(ChatAttachmentTextExtractor $extractor): void
    {
        $attachment = ChatAttachment::query()->find($this->attachmentId);

        if ($attachment === null) {
            return;
        }

        $attachment->update([
            'status' => ChatAttachment::STATUS_EXTRACTING,
            'error' => null,
        ]);

        try {
            $attachment->update([
                'extracted_markdown' => $extractor->extract($attachment),
                'status' => ChatAttachment::STATUS_READY,
            ]);
        } catch (Throwable $exception) {
            $attachment->update([
                'status' => ChatAttachment::STATUS_FAILED,
                'error' => Str::limit($exception->getMessage(), 1000),
            ]);

            report($exception);
        }
    }
}
