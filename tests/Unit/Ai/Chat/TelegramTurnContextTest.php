<?php

use App\Ai\Chat\AttachmentContext;
use App\Ai\Chat\TelegramTurnContext;
use App\Models\Ai\ChatAttachment;
use Telegram\Bot\Objects\Message;

function turnMessage(array $overrides = []): Message
{
    return new Message(array_replace_recursive([
        'message_id' => 20,
        'from' => ['id' => 501, 'is_bot' => false, 'first_name' => 'سعد', 'last_name' => 'باطويل', 'username' => 'saad', 'language_code' => 'ar'],
        'chat' => ['id' => 900123, 'type' => 'private', 'first_name' => 'سعد'],
        'text' => 'سيك كم مكافأة الامتياز؟',
    ], $overrides));
}

it('describes a private chat sender without a group title', function () {
    $wrapped = (new TelegramTurnContext)->wrap('كم مكافأة الامتياز؟', turnMessage());

    expect($wrapped)
        ->toContain('السائل: سعد باطويل (@saad)')
        ->toContain('نوع المحادثة: خاصة')
        ->toContain('لغة المستخدم: ar')
        ->not->toContain('اسم المجموعة')
        ->toEndWith("رسالة المستخدم:\nكم مكافأة الامتياز؟");
});

it('includes the group title for a group chat', function () {
    $message = turnMessage([
        'chat' => ['id' => -100777, 'type' => 'supergroup', 'title' => 'مجموعة الكلية'],
    ]);

    $wrapped = (new TelegramTurnContext)->wrap('سؤال', $message);

    expect($wrapped)
        ->toContain('نوع المحادثة: مجموعة')
        ->toContain('اسم المجموعة: مجموعة الكلية');
});

it('includes the quoted text and author when the ask is a reply', function () {
    $message = turnMessage([
        'reply_to_message' => [
            'message_id' => 5,
            'from' => ['id' => 99, 'is_bot' => false, 'first_name' => 'أحمد'],
            'chat' => ['id' => 900123, 'type' => 'private'],
            'text' => 'مكافأة الامتياز ألف ريال.',
        ],
    ]);

    $wrapped = (new TelegramTurnContext)->wrap('وكم لمرتبة الشرف؟', $message);

    expect($wrapped)->toContain('ردّاً على رسالة من أحمد: «مكافأة الامتياز ألف ريال.»');
});

it('truncates a long quoted reply', function () {
    $long = str_repeat('كلمة ', 400);

    $message = turnMessage([
        'reply_to_message' => [
            'message_id' => 5,
            'from' => ['id' => 99, 'is_bot' => false, 'first_name' => 'أحمد'],
            'chat' => ['id' => 900123, 'type' => 'private'],
            'text' => $long,
        ],
    ]);

    $preamble = (new TelegramTurnContext)->preambleFor($message);

    $replyLine = collect(explode("\n", $preamble))->first(fn (string $line): bool => str_starts_with($line, 'ردّاً'));

    expect(mb_strlen($replyLine))->toBeLessThan(560)
        ->and($replyLine)->toContain('...');
});

it('omits the username and last name cleanly when absent', function () {
    $message = turnMessage([
        'from' => ['id' => 501, 'is_bot' => false, 'first_name' => 'سعد', 'last_name' => null, 'username' => null, 'language_code' => null],
    ]);

    $preamble = (new TelegramTurnContext)->preambleFor($message);

    expect($preamble)
        ->toContain('السائل: سعد')
        ->not->toContain('(@')
        ->not->toContain('لغة المستخدم')
        ->not->toContain('باطويل');
});

it('is deterministic for identical input', function () {
    $context = new TelegramTurnContext;

    $first = $context->wrap('نفس السؤال', turnMessage());
    $second = $context->wrap('نفس السؤال', turnMessage());

    expect($first)->toBe($second);
});

it('composes as the outermost wrapper around the attachment block', function () {
    $attachment = new ChatAttachment([
        'original_filename' => 'transcript.png',
        'status' => ChatAttachment::STATUS_READY,
        'extracted_markdown' => 'المعدل التراكمي: 3.9',
    ]);

    $withAttachment = (new AttachmentContext)->wrap('كم معدلي؟', collect([$attachment]));
    $full = (new TelegramTurnContext)->wrap($withAttachment, turnMessage());

    $metadataSentinel = mb_strpos($full, 'سياق المحادثة');
    $attachmentSentinel = mb_strpos($full, 'مرفقات المستخدم');
    $userMessage = mb_strpos($full, 'كم معدلي؟');

    expect($metadataSentinel)->toBeLessThan($attachmentSentinel)
        ->and($attachmentSentinel)->toBeLessThan($userMessage)
        ->and($full)->toEndWith('كم معدلي؟');
});
