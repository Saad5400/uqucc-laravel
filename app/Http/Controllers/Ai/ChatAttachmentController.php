<?php

namespace App\Http\Controllers\Ai;

use App\Ai\Spend\SpendLedger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ChatAttachmentRequest;
use App\Jobs\Ai\ExtractChatAttachmentJob;
use App\Models\Ai\ChatAttachment;
use App\Settings\AiSettings;
use Illuminate\Http\JsonResponse;

/**
 * POST /ai/chat/attachments (name: ai.chat.attachments.store) — store one
 * chat upload (image or PDF, ≤10MB) for the current session and queue its
 * text extraction on the `ai` queue. The response hands back the attachment
 * id the client references in a subsequent /ai/chat message; the extracted
 * text is then injected into that turn as conversation context. Never
 * touches the public corpus.
 */
class ChatAttachmentController extends Controller
{
    public function __invoke(ChatAttachmentRequest $request, AiSettings $settings, SpendLedger $ledger): JsonResponse
    {
        if (! $settings->isFeatureEnabled('assistant')) {
            return response()->json(['message' => 'المساعد الذكي غير متاح حالياً.'], 503);
        }

        if (! $ledger->hasBudgetRemaining()) {
            return response()->json(['message' => $ledger->budgetExhaustedMessage()], 503);
        }

        $file = $request->file('file');

        $path = $file->store(ChatAttachment::DIRECTORY, ChatAttachment::DISK);

        $attachment = ChatAttachment::query()->create([
            'session_id' => $request->session()->getId(),
            'original_filename' => $file->getClientOriginalName(),
            'disk' => ChatAttachment::DISK,
            'path' => (string) $path,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'status' => ChatAttachment::STATUS_PENDING,
        ]);

        ExtractChatAttachmentJob::dispatch($attachment->id);

        return response()->json([
            'attachment_id' => $attachment->id,
            'status' => $attachment->status,
        ], 201);
    }
}
