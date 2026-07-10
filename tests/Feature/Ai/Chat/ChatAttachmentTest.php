<?php

use App\Ai\Chat\ChatAttachmentTextExtractor;
use App\Ai\Corpus\DocumentExtractionAgent;
use App\Jobs\Ai\ExtractChatAttachmentJob;
use App\Models\Ai\AiUsage;
use App\Models\Ai\ChatAttachment;
use App\Settings\AiSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(ChatAttachment::DISK);

    config()->set('ai.providers.openrouter.key', 'test-key');

    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->assistant_enabled = true;
    $settings->daily_budget_usd = 5.0;
    $settings->save();
});

/**
 * A stored image attachment whose file actually exists on the fake disk, so
 * the extractor can read it.
 */
function storedImageAttachment(array $attributes = []): ChatAttachment
{
    $attachment = ChatAttachment::factory()->image()->create($attributes);

    Storage::disk($attachment->disk)->put(
        $attachment->path,
        (string) UploadedFile::fake()->image('screenshot.png')->getContent(),
    );

    return $attachment;
}

it('stores the upload for the session and queues extraction on the ai queue', function () {
    Queue::fake();

    $response = $this->post(route('ai.chat.attachments.store'), [
        'file' => UploadedFile::fake()->create('السجل.pdf', 200, 'application/pdf'),
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['attachment_id', 'status'])
        ->assertJsonPath('status', ChatAttachment::STATUS_PENDING);

    $attachment = ChatAttachment::query()->sole();

    expect($attachment->id)->toBe($response->json('attachment_id'))
        ->and($attachment->session_id)->not->toBe('')
        ->and($attachment->original_filename)->toBe('السجل.pdf');

    Storage::disk(ChatAttachment::DISK)->assertExists($attachment->path);

    Queue::assertPushedOn('ai', ExtractChatAttachmentJob::class, fn (ExtractChatAttachmentJob $job) => $job->attachmentId === $attachment->id);
});

it('rejects invalid uploads', function (array $payload, string $field) {
    $this->postJson(route('ai.chat.attachments.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing file' => [[], 'file'],
    'unsupported type' => [fn () => ['file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')], 'file'],
    'file too large' => [fn () => ['file' => UploadedFile::fake()->create('big.pdf', 11_000, 'application/pdf')], 'file'],
]);

it('answers 503 while the daily budget is spent', function () {
    AiUsage::factory()->create(['cost' => 6.0]);

    $this->postJson(route('ai.chat.attachments.store'), [
        'file' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
    ])->assertServiceUnavailable();

    expect(ChatAttachment::query()->count())->toBe(0);
});

it('extracts an image through the faked vision model and records the spend', function () {
    DocumentExtractionAgent::fake(["## السجل الأكاديمي\nالمعدل: 3.75"]);

    $attachment = storedImageAttachment();

    (new ExtractChatAttachmentJob($attachment->id))->handle(app(ChatAttachmentTextExtractor::class));

    $attachment->refresh();

    expect($attachment->status)->toBe(ChatAttachment::STATUS_READY)
        ->and($attachment->extracted_markdown)->toContain('المعدل: 3.75')
        ->and(AiUsage::query()->where('feature', 'assistant_attachment')->count())->toBe(1);
});

it('fails the extraction politely when the daily budget is spent', function () {
    DocumentExtractionAgent::fake(['يجب ألا يُستدعى النموذج.']);

    AiUsage::factory()->create(['cost' => 6.0]);

    $attachment = storedImageAttachment();

    (new ExtractChatAttachmentJob($attachment->id))->handle(app(ChatAttachmentTextExtractor::class));

    $attachment->refresh();

    expect($attachment->status)->toBe(ChatAttachment::STATUS_FAILED)
        ->and($attachment->error)->toContain('غير متاح اليوم');

    DocumentExtractionAgent::assertNeverPrompted();
});

it('fails the extraction when the master ai kill switch is off', function () {
    DocumentExtractionAgent::fake(['يجب ألا يُستدعى النموذج.']);

    $settings = app(AiSettings::class);
    $settings->ai_enabled = false;
    $settings->save();

    $attachment = storedImageAttachment();

    (new ExtractChatAttachmentJob($attachment->id))->handle(app(ChatAttachmentTextExtractor::class));

    expect($attachment->refresh()->status)->toBe(ChatAttachment::STATUS_FAILED);

    DocumentExtractionAgent::assertNeverPrompted();
});

it('deleting an attachment removes its stored file', function () {
    $attachment = storedImageAttachment();

    $attachment->delete();

    Storage::disk(ChatAttachment::DISK)->assertMissing($attachment->path);
});
