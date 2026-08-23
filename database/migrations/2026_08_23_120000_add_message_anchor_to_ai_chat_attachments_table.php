<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anchor a sent chat attachment to the MESSAGE it was sent with.
 *
 * The table already carries `conversation_id`, bound once a turn resolves its
 * thread — enough for the retention cascade, which only ever asks "which
 * conversation is this filed under?". It cannot answer the question the chat UI
 * asks on every reload: "which bubble does this file belong to?". Without a
 * message anchor a rehydrated thread can only show every file the visitor ever
 * attached on every one of their messages, which is worse than showing none —
 * so it showed none, which is the defect this closes.
 *
 * `message_id` is NULLABLE and stays null until the conversation store has
 * actually written the user message; {@see \App\Jobs\Ai\GenerateChatReply}
 * binds it there, beside the existing `conversation_id` bind:
 *
 *  · A turn that never got that far — it failed, or the visitor stopped it
 *    before anything was stored — leaves its rows unanchored. Unanchored means
 *    no bubble claims the file, which is correct: it was never attached to a
 *    stored message. Those rows are still the uploader's own and still
 *    downloadable by them; the retention sweep in
 *    {@see \App\Listeners\PruneChatAttachments} collects them.
 *  · It is the STORE's id (a uuid7 string from laravel/ai), a plain string and
 *    deliberately NOT a foreign key: the message rows live behind a
 *    configurable table name and a swappable store, and an attachment must
 *    never be deleted as a side effect of history pruning it knows nothing
 *    about. `conversation_id` is already a bare string for the same reason.
 *
 * NO NEW INDEX, on purpose. The one read is the thread endpoint's
 * `where conversation_id = ?`, which fetches a conversation's attachments in a
 * single query and groups them by message in PHP — never one query per bubble —
 * and `conversation_id` has carried its own index since the table was created.
 * A composite `(conversation_id, message_id)` could not cover that read either
 * (it also selects the filename, mime, size and path), so it would buy nothing
 * and cost every insert.
 *
 * Guarded (`hasTable` / `hasColumn`) so a re-run against an out-of-sync
 * database is a no-op — the never-edit-a-run-migration rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_chat_attachments')
            || Schema::hasColumn('ai_chat_attachments', 'message_id')) {
            return;
        }

        Schema::table('ai_chat_attachments', function (Blueprint $table) {
            $table->string('message_id', 64)->nullable()->after('conversation_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ai_chat_attachments', 'message_id')) {
            return;
        }

        Schema::table('ai_chat_attachments', function (Blueprint $table) {
            $table->dropColumn('message_id');
        });
    }
};
