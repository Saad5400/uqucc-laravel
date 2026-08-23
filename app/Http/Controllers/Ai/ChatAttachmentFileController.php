<?php

namespace App\Http\Controllers\Ai;

use App\Ai\Chat\SessionOwner;
use App\Http\Controllers\Controller;
use App\Models\Ai\ChatAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Saad\AiKit\Conversations\ConversationOwnership;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /ai/chat/attachments/{attachment} (name: ai.chat.attachments.show) —
 * the one door onto a chat attachment's bytes: the file the visitor themselves
 * attached to a sent message, opened from the chip on that message's bubble.
 *
 * SESSION-OWNED, because that is the only identity this surface has: the public
 * site has no accounts, so the uploader is the session that stored the row (the
 * same identity {@see ChatController::stream()} and `cancel()` gate a turn on,
 * and the same one {@see SessionOwner} hands laravel/ai as the conversation
 * participant). A visitor whose session ends loses access to their own
 * uploads — that is the honest consequence of an anonymous surface, and it
 * matches what already happens to their threads.
 *
 * THE REFUSAL IS 404, NEVER 403, and that is a deliberate posture rather than
 * laziness about status codes. A 403 confirms the id exists, which turns the
 * ULID space into a census oracle: walk it, and every 403 is somebody else's
 * real file. Collapsing "no such attachment" and "not yours" into one answer
 * makes a stranger's id indistinguishable from a made-up one, so a prober
 * learns nothing either way. The ownership filter is therefore part of the
 * LOOKUP — not a check after a successful find — so no branch exists between
 * the two cases that could later grow a distinguishable response.
 *
 * There is no signed URL and no public URL: the bytes are streamed through this
 * authorized route so the gate sits on every fetch, and one code path serves
 * the local dev disk and S3 alike.
 */
class ChatAttachmentFileController extends Controller
{
    public function __invoke(
        Request $request,
        ConversationOwnership $ownership,
        string $attachment,
    ): StreamedResponse {
        $sessionId = $request->session()->getId();

        $row = ChatAttachment::query()
            ->ownedBySession($sessionId)
            ->whereKey($attachment)
            ->first();

        abort_if($row === null, 404);

        // Second gate, and not a redundant one. `session_id` says who uploaded
        // the bytes; this says the THREAD they are filed under is also this
        // session's, so an attachment can never be read through a conversation
        // its owner no longer holds. The two can only disagree through a bug,
        // which is what defence in depth is for, and it costs one indexed
        // lookup taken only when the row is anchored at all.
        //
        // An UNANCHORED row is not refused. Those are uploads whose turn never
        // stored a message (it failed, or the visitor stopped it) plus any row
        // written before the anchor column existed; they are still this
        // session's own files and `session_id` already proved it. Refusing them
        // would hide a visitor's own upload to enforce an anchor that was never
        // written.
        if ($row->conversation_id !== null
            && ! $ownership->owns($row->conversation_id, $sessionId, SessionOwner::class)) {
            abort(404);
        }

        $disk = Storage::disk($row->disk);

        // 404, not 500, when the row outlived its file: the retention sweep in
        // PruneChatAttachments makes that a routine edge, never a server fault.
        abort_unless($disk->exists($row->path), 404);

        $mime = (string) $row->mime !== '' ? (string) $row->mime : 'application/octet-stream';

        return $disk->response(
            $row->path,
            $row->original_filename,
            [
                'Content-Type' => $mime,
                // Holds the declared type whether the file renders inline or
                // downloads, so an innocent extension can never be sniffed
                // into an active document on our own origin.
                'X-Content-Type-Options' => 'nosniff',
                // A visitor's private upload: never store it in a shared cache.
                'Cache-Control' => 'private, max-age=0, no-store',
            ],
            $row->isInlineSafe() ? 'inline' : 'attachment',
        );
    }
}
