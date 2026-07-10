<?php

use App\Jobs\Ai\ExtractCorpusDocumentJob;
use App\Jobs\Ai\IngestDocumentJob;
use App\Models\Corpus\CorpusDocument;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

describe('corpus documents manage area', function () {
    it('redirects guests to the panel login', function () {
        $this->get('/manage/corpus')->assertRedirect(route('manage.login'));
    });

    it('returns 403 for users without a panel role', function () {
        $this->actingAs(User::factory()->create());

        $this->get('/manage/corpus')->assertForbidden();
    });

    it('loads the list page for an admin', function () {
        CorpusDocument::factory()->ready()->create(['title' => 'لائحة الدراسة والاختبارات']);
        CorpusDocument::factory()->failed()->create();

        $this->actingAs($this->admin)
            ->get('/manage/corpus')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('manage/corpus/Index')
                ->count('documents.data', 2)
                ->where('documents.data.1.title', 'لائحة الدراسة والاختبارات')
                ->where('documents.data.1.status', CorpusDocument::STATUS_READY)
                ->where('documents.data.1.kind', 'pdf')
                ->where('documents.data.0.status', CorpusDocument::STATUS_FAILED)
            );
    });

    it('filters the list by status and searches by title', function () {
        CorpusDocument::factory()->ready()->create(['title' => 'لائحة الدراسة']);
        CorpusDocument::factory()->failed()->create(['title' => 'دليل مستعمل']);

        $this->actingAs($this->admin)
            ->get('/manage/corpus?status=ready')
            ->assertInertia(fn (Assert $page) => $page
                ->count('documents.data', 1)
                ->where('documents.data.0.title', 'لائحة الدراسة')
            );

        $this->actingAs($this->admin)
            ->get('/manage/corpus?search=دليل')
            ->assertInertia(fn (Assert $page) => $page
                ->count('documents.data', 1)
                ->where('documents.data.0.title', 'دليل مستعمل')
            );
    });

    it('loads the edit page with the extracted markdown for an admin', function () {
        $document = CorpusDocument::factory()->ready()->create();

        $this->actingAs($this->admin)
            ->get("/manage/corpus/{$document->id}/edit")
            ->assertSuccessful()
            ->assertInertia(fn (Assert $page) => $page
                ->component('manage/corpus/Edit')
                ->where('document.id', $document->id)
                ->where('document.extracted_markdown', $document->extracted_markdown)
            );
    });
});

describe('corpus document upload', function () {
    it('stores the file, derives mime and size from the stored bytes, and queues extraction', function () {
        Queue::fake();
        Storage::fake(CorpusDocument::DISK);

        $this->actingAs($this->admin)
            ->from('/manage/corpus')
            ->post('/manage/corpus', [
                'title' => 'لائحة الدراسة والاختبارات',
                'file' => UploadedFile::fake()->image('regulation.png', 100, 100),
            ])
            ->assertRedirect('/manage/corpus')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $document = CorpusDocument::query()->sole();

        expect($document->title)->toBe('لائحة الدراسة والاختبارات')
            ->and($document->original_filename)->toBe('regulation.png')
            ->and($document->disk)->toBe(CorpusDocument::DISK)
            ->and($document->status)->toBe(CorpusDocument::STATUS_PENDING)
            ->and($document->mime)->toBe('image/png')
            ->and($document->size)->toBeGreaterThan(0)
            ->and($document->uploaded_by)->toBe($this->admin->id);

        Storage::disk(CorpusDocument::DISK)->assertExists($document->path);

        Queue::assertPushed(ExtractCorpusDocumentJob::class, fn (ExtractCorpusDocumentJob $job) => $job->documentId === $document->id);
    });

    it('accepts plain-text and markdown uploads and queues extraction', function (string $filename, string $storedMime) {
        Queue::fake();
        Storage::fake(CorpusDocument::DISK);

        $this->actingAs($this->admin)
            ->post('/manage/corpus', [
                'title' => 'دليل نصي',
                'file' => UploadedFile::fake()->createWithContent($filename, "# دليل الطالب\n\nمحتوى نصي كافٍ للفهرسة في البحث الذكي."),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $document = CorpusDocument::query()->sole();

        expect($document->original_filename)->toBe($filename)
            ->and($document->mime)->toBe($storedMime)
            ->and($document->status)->toBe(CorpusDocument::STATUS_PENDING);

        Queue::assertPushed(ExtractCorpusDocumentJob::class, fn (ExtractCorpusDocumentJob $job) => $job->documentId === $document->id);
    })->with([
        'txt file' => ['notes.txt', 'text/plain'],
        'md file' => ['guide.md', 'text/markdown'],
    ]);

    it('rejects invalid uploads with Arabic messages', function (array $payload, string $field, string $message) {
        Queue::fake();
        Storage::fake(CorpusDocument::DISK);

        $this->actingAs($this->admin)
            ->post('/manage/corpus', $payload)
            ->assertSessionHasErrors([$field => $message]);

        expect(CorpusDocument::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'missing title' => [
            ['file' => UploadedFile::fake()->image('a.png')],
            'title',
            'حقل العنوان مطلوب.',
        ],
        'missing file' => [
            ['title' => 'مستند'],
            'file',
            'حقل الملف مطلوب.',
        ],
        'unsupported type' => [
            ['title' => 'مستند', 'file' => UploadedFile::fake()->createWithContent('archive.zip', "PK\x03\x04".random_bytes(64))],
            'file',
            'الملف يجب أن يكون PDF أو صورة (PNG / JPG / WebP) أو ملفاً نصياً (TXT / MD).',
        ],
        'oversized file' => [
            ['title' => 'مستند', 'file' => UploadedFile::fake()->create('big.pdf', 20481, 'application/pdf')],
            'file',
            'حجم الملف يتجاوز الحد الأقصى (20 ميجابايت).',
        ],
    ]);
});

describe('corpus text paste', function () {
    it('stores pasted text as a ready .md document and queues ingestion only', function () {
        Queue::fake();
        Storage::fake(CorpusDocument::DISK);

        $this->actingAs($this->admin)
            ->from('/manage/corpus')
            ->post('/manage/corpus/text', [
                'title' => 'تعميم مواعيد الاختبارات',
                'content' => "## المواعيد\r\n\r\nتبدأ الاختبارات النهائية في الأسبوع السادس عشر من الفصل الدراسي.",
            ])
            ->assertRedirect('/manage/corpus')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $document = CorpusDocument::query()->sole();

        expect($document->title)->toBe('تعميم مواعيد الاختبارات')
            ->and($document->original_filename)->toBe('تعميم مواعيد الاختبارات.md')
            ->and($document->disk)->toBe(CorpusDocument::DISK)
            ->and($document->path)->toEndWith('.md')
            ->and($document->mime)->toBe('text/markdown')
            ->and($document->status)->toBe(CorpusDocument::STATUS_READY)
            ->and($document->extracted_markdown)->toBe("## المواعيد\n\nتبدأ الاختبارات النهائية في الأسبوع السادس عشر من الفصل الدراسي.")
            ->and($document->size)->toBeGreaterThan(0)
            ->and($document->uploaded_by)->toBe($this->admin->id);

        Storage::disk(CorpusDocument::DISK)->assertExists($document->path);

        expect(Storage::disk(CorpusDocument::DISK)->get($document->path))->toBe($document->extracted_markdown);

        Queue::assertPushed(IngestDocumentJob::class, fn (IngestDocumentJob $job) => $job->documentId === $document->id);
        Queue::assertNotPushed(ExtractCorpusDocumentJob::class);
    });

    it('rejects invalid pasted text with Arabic messages', function (array $payload, string $field, string $message) {
        Queue::fake();
        Storage::fake(CorpusDocument::DISK);

        $this->actingAs($this->admin)
            ->post('/manage/corpus/text', $payload)
            ->assertSessionHasErrors([$field => $message]);

        expect(CorpusDocument::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'missing title' => [
            ['content' => str_repeat('نص طويل بما يكفي. ', 10)],
            'title',
            'حقل العنوان مطلوب.',
        ],
        'missing content' => [
            ['title' => 'تعميم'],
            'content',
            'حقل النص مطلوب.',
        ],
        'content too short' => [
            ['title' => 'تعميم', 'content' => 'نص قصير'],
            'content',
            'النص قصير جداً — أدخل ٥٠ حرفاً على الأقل.',
        ],
        'content too long' => [
            ['title' => 'تعميم', 'content' => str_repeat('ن', 500001)],
            'content',
            'النص يتجاوز الحد الأقصى (٥٠٠ ألف حرف).',
        ],
    ]);

    it('requires a panel role to paste text', function () {
        $this->actingAs(User::factory()->create());

        $this->post('/manage/corpus/text', [
            'title' => 'تعميم',
            'content' => str_repeat('نص طويل بما يكفي. ', 10),
        ])->assertForbidden();
    });
});

describe('corpus document update', function () {
    it('saves a manual correction and queues re-indexing when the markdown changes', function () {
        Queue::fake();
        $document = CorpusDocument::factory()->ready()->create();

        $this->actingAs($this->admin)
            ->put("/manage/corpus/{$document->id}", [
                'title' => $document->title,
                'extracted_markdown' => "## نص مصحح\n\nمحتوى معدل يدوياً.",
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        expect($document->refresh()->extracted_markdown)->toContain('محتوى معدل يدوياً.');

        Queue::assertPushed(IngestDocumentJob::class, fn (IngestDocumentJob $job) => $job->documentId === $document->id);
    });

    it('does not queue re-indexing when nothing changed', function () {
        Queue::fake();
        $document = CorpusDocument::factory()->ready()->create();

        $this->actingAs($this->admin)
            ->put("/manage/corpus/{$document->id}", [
                'title' => $document->title,
                'extracted_markdown' => $document->extracted_markdown,
            ])
            ->assertSessionHasNoErrors();

        Queue::assertNothingPushed();
    });
});

describe('corpus document actions', function () {
    it('queues a re-extraction', function () {
        Queue::fake();
        $document = CorpusDocument::factory()->failed()->create();

        $this->actingAs($this->admin)
            ->post("/manage/corpus/{$document->id}/reextract")
            ->assertSessionHas('success');

        Queue::assertPushed(ExtractCorpusDocumentJob::class, fn (ExtractCorpusDocumentJob $job) => $job->documentId === $document->id);
    });

    it('queues a re-ingestion for a ready document', function () {
        Queue::fake();
        $document = CorpusDocument::factory()->ready()->create();

        $this->actingAs($this->admin)
            ->post("/manage/corpus/{$document->id}/reingest")
            ->assertSessionHas('success');

        Queue::assertPushed(IngestDocumentJob::class, fn (IngestDocumentJob $job) => $job->documentId === $document->id);
    });

    it('refuses to re-ingest before extraction completed', function () {
        Queue::fake();
        $document = CorpusDocument::factory()->create();

        $this->actingAs($this->admin)
            ->post("/manage/corpus/{$document->id}/reingest")
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    });

    it('deletes a document', function () {
        $document = CorpusDocument::factory()->ready()->create();

        $this->actingAs($this->admin)
            ->delete("/manage/corpus/{$document->id}")
            ->assertRedirect('/manage/corpus')
            ->assertSessionHas('success');

        expect(CorpusDocument::query()->find($document->id))->toBeNull();
    });
});
