<?php

namespace App\Ai\Chat;

use App\Models\Ai\ChatAttachment;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Folds the extracted text of a turn's attachments into the prompt the model
 * sees (so the assistant can, e.g., read a transcript screenshot and call
 * calculate_gpa), and strips that wrapper back off when a stored user message
 * is returned to the client — the visitor typed only the message, not the
 * evidence blocks, and the history endpoint must reflect that.
 *
 * The wrapper starts with a fixed sentinel line, so unwrap() only ever
 * touches content this class produced.
 */
class AttachmentContext
{
    /** First line of every wrapped prompt — the unwrap guard. */
    private const SENTINEL = '[مرفقات المستخدم — نصوص مستخرجة للقراءة فقط]';

    /** Marker separating the evidence blocks from the visitor's own message. */
    private const MESSAGE_MARKER = 'رسالة المستخدم:';

    /**
     * Wrap the visitor's message with the attachments' extracted text. With
     * no attachments the message passes through unchanged, keeping plain
     * turns byte-identical (and provider-cacheable).
     *
     * @param  Collection<int, ChatAttachment>  $attachments
     */
    public function wrap(string $message, Collection $attachments): string
    {
        if ($attachments->isEmpty()) {
            return $message;
        }

        $blocks = $attachments
            ->map(fn (ChatAttachment $attachment): string => 'Attached file: '.$attachment->original_filename."\n".$this->blockFor($attachment))
            ->implode("\n\n");

        return self::SENTINEL."\n".'أرفق المستخدم الملفات التالية وتم استخراج نصوصها أدناه. استخدمها كمرجع للإجابة عن رسالته.'
            ."\n\n".$blocks
            ."\n\n".self::MESSAGE_MARKER."\n".$message;
    }

    /**
     * Return the visitor's original message from a stored (possibly wrapped)
     * user message. Content this class did not produce passes through.
     */
    public function unwrap(string $content): string
    {
        if (! str_starts_with($content, self::SENTINEL)) {
            return $content;
        }

        $marker = self::MESSAGE_MARKER."\n";

        if (! str_contains($content, $marker)) {
            return $content;
        }

        return Str::after($content, $marker);
    }

    /**
     * The evidence block for one attachment: its extracted markdown when
     * ready, otherwise an honest status note so the model never guesses at
     * unread content.
     */
    private function blockFor(ChatAttachment $attachment): string
    {
        if ($attachment->isReady() && (string) $attachment->extracted_markdown !== '') {
            return (string) $attachment->extracted_markdown;
        }

        if ($attachment->status === ChatAttachment::STATUS_FAILED) {
            return '[تعذر استخراج نص هذا الملف — أخبر المستخدم أنك لم تستطع قراءته.]';
        }

        return '[ما زال هذا الملف قيد المعالجة — أخبر المستخدم أن يعيد المحاولة بعد لحظات.]';
    }
}
