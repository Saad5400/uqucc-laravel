<?php

use App\Ai\Agents\StudentAssistant;
use App\Ai\Chat\AttachmentContext;
use App\Ai\Chat\ChatAttachmentTextExtractor;
use App\Ai\Corpus\DocumentExtractionAgent;
use App\Ai\Spend\SpendLedger;
use App\Models\Ai\AiUsage;
use App\Models\Ai\ChatAttachment;
use App\Models\TelegramChatSetting;
use App\Services\Telegram\Handlers\AiChatHandler;
use App\Settings\AiSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use Telegram\Bot\Objects\Message;
use Tests\Fakes\FakeTelegramApi;

beforeEach(function () {
    config()->set('ai.embeddings.driver', 'fake');
    config()->set('ai.embeddings.dimensions', 64);

    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->telegram_ai_enabled = true;
    $settings->daily_budget_usd = 5.0;
    $settings->per_conversation_rate_limit = 30;
    $settings->save();
});

function aiChatHandler(FakeTelegramApi $api): AiChatHandler
{
    return new AiChatHandler(
        $api,
        app(AiSettings::class),
        app(SpendLedger::class),
        app(ChatAttachmentTextExtractor::class),
        app(AttachmentContext::class),
    );
}

function aiChatMessage(array $overrides = []): Message
{
    return new Message(array_replace_recursive([
        'message_id' => 20,
        'from' => ['id' => 501, 'is_bot' => false, 'first_name' => 'سعد'],
        'chat' => ['id' => 900123, 'type' => 'private', 'first_name' => 'سعد'],
        'text' => 'سيك كم مكافأة الامتياز؟',
    ], $overrides));
}

function groupAiChatMessage(array $overrides = []): Message
{
    return aiChatMessage(array_replace_recursive([
        'chat' => ['id' => -100777, 'type' => 'supergroup', 'title' => 'مجموعة الكلية'],
    ], $overrides));
}

function activatedChat(int $chatId = 900123): TelegramChatSetting
{
    return TelegramChatSetting::factory()->aiEnabled()->create(['chat_id' => $chatId]);
}

it('stays silent in a chat where the assistant is not activated', function () {
    StudentAssistant::fake(['يجب ألا يظهر هذا الرد.']);

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(aiChatMessage());

    StudentAssistant::assertNeverPrompted();

    expect($api->sentMessages)->toBe([]);
});

it('stays silent in a deactivated chat even when a row exists', function () {
    StudentAssistant::fake(['يجب ألا يظهر هذا الرد.']);

    TelegramChatSetting::factory()->create(['chat_id' => 900123, 'ai_enabled' => false]);

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(aiChatMessage());

    StudentAssistant::assertNeverPrompted();

    expect($api->sentMessages)->toBe([]);
});

it('stays silent while the global telegram ai toggle is off', function () {
    StudentAssistant::fake(['يجب ألا يظهر هذا الرد.']);

    activatedChat();

    $settings = app(AiSettings::class);
    $settings->telegram_ai_enabled = false;
    $settings->save();

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(aiChatMessage());

    StudentAssistant::assertNeverPrompted();

    expect($api->sentMessages)->toBe([]);
});

it('replies in an activated private chat and stores the conversation for the chat', function () {
    StudentAssistant::fake(['مكافأة الامتياز ألف ريال.']);

    $chatSettings = activatedChat();

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(aiChatMessage());

    expect($api->sentMessages[0]['text'])->toBe('جاري المعالجة…')
        ->and($api->sentMessages[0]['reply_to_message_id'])->toBe(20)
        ->and(implode(' ', $api->allTexts()))->toContain('مكافأة الامتياز ألف ريال');

    $conversation = Conversation::query()->sole();

    expect($conversation->getAttribute('user_id'))->toBe('telegram:900123')
        ->and($chatSettings->refresh()->conversation_id)->toBe($conversation->getKey());

    $usage = AiUsage::query()->sole();

    expect($usage->feature)->toBe('telegram');
});

it('stays silent in an activated private chat when the message does not address the bot', function () {
    StudentAssistant::fake(['يجب ألا يظهر هذا الرد.']);

    activatedChat();

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(aiChatMessage(['text' => 'كم مكافأة الامتياز؟']));

    StudentAssistant::assertNeverPrompted();

    expect($api->sentMessages)->toBe([]);
});

it('strips the سيك prefix from the prompt', function () {
    StudentAssistant::fake(['مكافأة الامتياز ألف ريال.']);

    activatedChat();

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(aiChatMessage(['text' => 'سيك كم مكافأة الامتياز؟']));

    StudentAssistant::assertPrompted(fn ($prompt) => $prompt->prompt === 'كم مكافأة الامتياز؟');
});

it('answers a private follow-up that replies to one of the bot messages', function () {
    StudentAssistant::fake(['رد المتابعة.']);

    activatedChat();

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(aiChatMessage([
        'text' => 'وكم لمرتبة الشرف؟',
        'reply_to_message' => [
            'message_id' => 5,
            'from' => ['id' => 42, 'is_bot' => true, 'username' => 'UquccTestBot'],
            'chat' => ['id' => 900123, 'type' => 'private'],
            'text' => 'مكافأة الامتياز ألف ريال.',
        ],
    ]));

    StudentAssistant::assertPrompted(fn ($prompt) => $prompt->prompt === 'وكم لمرتبة الشرف؟');
});

it('continues the same conversation across turns and starts fresh after a reset', function () {
    StudentAssistant::fake(fn () => 'رد.');

    $chatSettings = activatedChat();

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(aiChatMessage(['text' => 'سيك سؤال أول']));
    aiChatHandler($api)->handle(aiChatMessage(['text' => 'سيك سؤال ثانٍ']));

    $conversationId = $chatSettings->refresh()->conversation_id;

    expect(Conversation::query()->count())->toBe(1)
        ->and(ConversationMessage::query()->where('conversation_id', $conversationId)->count())->toBe(4);

    // /ai_new clears the stored id; the next turn starts a new thread.
    $chatSettings->update(['conversation_id' => null]);

    aiChatHandler($api)->handle(aiChatMessage(['text' => 'سيك سؤال ثالث']));

    expect(Conversation::query()->count())->toBe(2)
        ->and($chatSettings->refresh()->conversation_id)->not->toBe($conversationId);
});

it('starts fresh when the stored conversation was pruned', function () {
    StudentAssistant::fake(['رد.']);

    $chatSettings = activatedChat();
    $chatSettings->update(['conversation_id' => '0197fa00-0000-7000-8000-000000000000']);

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(aiChatMessage());

    expect(Conversation::query()->count())->toBe(1)
        ->and($chatSettings->refresh()->conversation_id)->toBe(Conversation::query()->sole()->getKey());
});

it('ignores group messages that do not address the bot', function () {
    StudentAssistant::fake(['يجب ألا يظهر هذا الرد.']);

    activatedChat(-100777);

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(groupAiChatMessage(['text' => 'كم مكافأة الامتياز؟']));

    StudentAssistant::assertNeverPrompted();

    expect($api->sentMessages)->toBe([]);
});

it('answers group messages that mention the bot, stripping the mention from the prompt', function () {
    StudentAssistant::fake(['المكافأة ألف ريال.']);

    activatedChat(-100777);

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(groupAiChatMessage(['text' => '@UquccTestBot كم مكافأة الامتياز؟']));

    StudentAssistant::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'كم مكافأة الامتياز؟')
        && ! str_contains($prompt->prompt, '@UquccTestBot'));
});

it('answers group messages that reply to the bot', function () {
    StudentAssistant::fake(['رد.']);

    activatedChat(-100777);

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(groupAiChatMessage([
        'reply_to_message' => [
            'message_id' => 5,
            'from' => ['id' => 42, 'is_bot' => true, 'username' => 'UquccTestBot'],
            'chat' => ['id' => -100777, 'type' => 'supergroup'],
            'text' => 'رد سابق من البوت',
        ],
    ]));

    StudentAssistant::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'كم مكافأة الامتياز؟'));
});

it('keeps the legacy اسال سيك command working through the shared assistant', function () {
    StudentAssistant::fake(['المعدل يحسب من النقاط.']);

    activatedChat(-100777);

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(groupAiChatMessage(['text' => 'اسال سيك كيف يحسب المعدل؟']));

    StudentAssistant::assertPrompted(fn ($prompt) => $prompt->prompt === 'كيف يحسب المعدل؟');
});

it('leaves slash commands and other handlers\' commands alone', function (string $text) {
    StudentAssistant::fake(['يجب ألا يظهر هذا الرد.']);

    activatedChat();

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(aiChatMessage(['text' => $text]));

    StudentAssistant::assertNeverPrompted();

    expect($api->sentMessages)->toBe([]);
})->with([
    'slash command' => '/help',
    'page lookup' => 'دليل المكافأة',
    'search' => 'بحث الحرمان',
    'google' => 'قوقل جامعة أم القرى',
    'python' => "شغل بايثون print('hi')",
    'index' => 'الفهرس',
    'login flow' => 'تسجيل دخول',
]);

it('enforces the per-chat daily quota with a single arabic notice', function () {
    StudentAssistant::fake(fn () => 'رد.');

    $settings = app(AiSettings::class);
    $settings->per_conversation_rate_limit = 2;
    $settings->save();

    activatedChat();

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(aiChatMessage(['text' => 'سيك سؤال 1']));
    aiChatHandler($api)->handle(aiChatMessage(['text' => 'سيك سؤال 2']));

    expect(AiUsage::query()->where('feature', 'telegram')->count())->toBe(2);

    aiChatHandler($api)->handle(aiChatMessage(['text' => 'سيك سؤال 3']));
    aiChatHandler($api)->handle(aiChatMessage(['text' => 'سيك سؤال 4']));

    $notices = array_filter($api->allTexts(), fn (string $text) => str_contains($text, 'حدها اليومي'));

    expect($notices)->toHaveCount(1)
        ->and(AiUsage::query()->where('feature', 'telegram')->count())->toBe(2);

    // A different chat still has its own quota.
    activatedChat(555999);

    aiChatHandler($api)->handle(aiChatMessage(['chat' => ['id' => 555999]]));

    expect(AiUsage::query()->where('feature', 'telegram')->count())->toBe(3);
});

it('enforces the burst limit per chat with a single notice', function () {
    StudentAssistant::fake(fn () => 'رد.');

    activatedChat();

    $api = new FakeTelegramApi;

    foreach (range(1, 5) as $i) {
        aiChatHandler($api)->handle(aiChatMessage(['text' => "سيك سؤال {$i}"]));
    }

    aiChatHandler($api)->handle(aiChatMessage(['text' => 'سيك السادسة']));
    aiChatHandler($api)->handle(aiChatMessage(['text' => 'سيك السابعة']));

    $notices = array_filter($api->allTexts(), fn (string $text) => str_contains($text, 'انتظر دقيقة'));

    expect($notices)->toHaveCount(1)
        ->and(AiUsage::query()->where('feature', 'telegram')->count())->toBe(5);
});

it('refuses politely without calling the model once the daily budget is spent', function () {
    StudentAssistant::fake(['يجب ألا يظهر هذا الرد.']);

    AiUsage::factory()->create(['cost' => 6.0]);

    activatedChat();

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(aiChatMessage());

    StudentAssistant::assertNeverPrompted();

    expect($api->sentMessages[0]['text'])->toContain('غير متاح اليوم');
});

it('chunks replies longer than the telegram message limit', function () {
    StudentAssistant::fake([trim(str_repeat('كلمة طويلة ', 800))]);

    activatedChat();

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(aiChatMessage());

    // Placeholder + at least one follow-up chunk; the edit carries chunk one.
    expect(count($api->sentMessages))->toBeGreaterThanOrEqual(2)
        ->and($api->editedMessages)->toHaveCount(1);

    foreach ($api->allTexts() as $text) {
        expect(mb_strlen($text))->toBeLessThanOrEqual(4096);
    }
});

it('extracts a captioned photo and injects the text as turn context', function () {
    Storage::fake(ChatAttachment::DISK);

    config()->set('ai.providers.openrouter.key', 'test-key');

    DocumentExtractionAgent::fake(["## السجل الأكاديمي\nالمعدل التراكمي: 3.9"]);
    StudentAssistant::fake(['معدلك التراكمي 3.9.']);

    activatedChat();

    $api = new FakeTelegramApi;
    $api->downloadContents = (string) UploadedFile::fake()->image('transcript.png')->getContent();

    aiChatHandler($api)->handle(aiChatMessage([
        'text' => null,
        'caption' => 'سيك كم معدلي؟',
        'photo' => [
            ['file_id' => 'small', 'width' => 90, 'height' => 90],
            ['file_id' => 'large', 'width' => 800, 'height' => 800],
        ],
    ]));

    // The reply is MarkdownV2-escaped, so match on the unescaped part.
    expect($api->sentMessages[0]['text'])->toBe('جاري قراءة الملف…')
        ->and(implode(' ', $api->allTexts()))->toContain('معدلك التراكمي');

    StudentAssistant::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'المعدل التراكمي: 3.9')
        && str_contains($prompt->prompt, 'كم معدلي؟'));

    // The temporary attachment (and its stored file) is cleaned up after the turn.
    expect(ChatAttachment::query()->count())->toBe(0)
        ->and(Storage::disk(ChatAttachment::DISK)->files(ChatAttachment::DIRECTORY))->toBe([]);

    expect(AiUsage::query()->where('feature', 'assistant_attachment')->count())->toBe(1)
        ->and(AiUsage::query()->where('feature', 'telegram')->count())->toBe(1);
});

it('tells the chat when the attachment cannot be read', function () {
    Storage::fake(ChatAttachment::DISK);

    StudentAssistant::fake(['يجب ألا يظهر هذا الرد.']);

    // The master switch allows telegram AI but vision extraction is blocked
    // by the missing OpenRouter key, so extraction fails cleanly.
    config()->set('ai.providers.openrouter.key', '');

    activatedChat();

    $api = new FakeTelegramApi;
    $api->downloadContents = 'not-really-an-image';

    aiChatHandler($api)->handle(aiChatMessage([
        'text' => null,
        'caption' => 'سيك اقرأ الملف',
        'photo' => [['file_id' => 'p1', 'width' => 100, 'height' => 100]],
    ]));

    StudentAssistant::assertNeverPrompted();

    expect(end($api->editedMessages)['text'])->toContain('تعذر قراءة الملف')
        ->and(ChatAttachment::query()->count())->toBe(0);
});

it('ignores captioned documents with unsupported mimes', function () {
    StudentAssistant::fake(['يجب ألا يظهر هذا الرد.']);

    activatedChat();

    $api = new FakeTelegramApi;

    aiChatHandler($api)->handle(aiChatMessage([
        'text' => null,
        'caption' => 'سيك اقرأ الملف',
        'document' => ['file_id' => 'd1', 'file_name' => 'doc.docx', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    ]));

    // No attachment path: the caption alone still counts as a text prompt.
    StudentAssistant::assertPrompted(fn ($prompt) => $prompt->prompt === 'اقرأ الملف');

    expect($api->sentMessages[0]['text'])->toBe('جاري المعالجة…')
        ->and(ChatAttachment::query()->count())->toBe(0);
});
