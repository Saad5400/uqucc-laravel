<?php

use App\Ai\Authoring\PageAuthoringAgent;
use App\Jobs\Ai\AuthorPageFromDocumentJob;
use App\Models\Ai\AiUsage;
use App\Models\Ai\PageContentProposal;
use App\Models\Corpus\CorpusDocument;
use App\Models\Page;
use App\Models\User;
use App\Settings\AiSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    config()->set('ai.providers.openrouter.key', 'test-key');
    config()->set('ai.embeddings.driver', 'fake');
    config()->set('ai.embeddings.dimensions', 64);

    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->admin_copilot_enabled = true;
    $settings->search_enabled = true;
    $settings->daily_budget_usd = 5.0;
    $settings->save();
});

function disablePageAuthoring(bool $masterToo = false): void
{
    $settings = app(AiSettings::class);
    $settings->admin_copilot_enabled = false;

    if ($masterToo) {
        $settings->ai_enabled = false;
    }

    $settings->save();
}

/**
 * A page whose content lands in the corpus (the Page observer ingests on
 * save because AI search is on and the embedding driver is fake), making it
 * a retrievable update candidate for the authoring match step.
 */
function seedCandidatePage(string $title, string $body): Page
{
    return Page::factory()->create([
        'title' => $title,
        'html_content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $body]]],
            ],
        ],
    ]);
}

function runAuthoringJob(CorpusDocument $document): CorpusDocument
{
    (new AuthorPageFromDocumentJob($document->id))->handle(app(App\Ai\Authoring\PageAuthor::class));

    return $document->refresh();
}

describe('trigger endpoint', function () {
    it('returns 403 while the admin copilot feature is disabled', function (bool $masterToo) {
        Queue::fake();
        disablePageAuthoring($masterToo);

        $document = CorpusDocument::factory()->ready()->create();

        $this->actingAs($this->admin)
            ->post("/manage/corpus/{$document->id}/author")
            ->assertForbidden();

        Queue::assertNothingPushed();
        expect($document->refresh()->authoring_status)->toBeNull();
    })->with(['feature flag off' => false, 'master kill switch off' => true]);

    it('queues the authoring job and marks the document queued', function () {
        Queue::fake();

        $document = CorpusDocument::factory()->ready()->create();

        $this->actingAs($this->admin)
            ->from("/manage/corpus/{$document->id}/edit")
            ->post("/manage/corpus/{$document->id}/author")
            ->assertRedirect("/manage/corpus/{$document->id}/edit")
            ->assertSessionHas('success');

        expect($document->refresh()->authoring_status)->toBe(CorpusDocument::AUTHORING_QUEUED);

        Queue::assertPushed(
            AuthorPageFromDocumentJob::class,
            fn (AuthorPageFromDocumentJob $job): bool => $job->documentId === $document->id && $job->queue === 'ai'
        );
    });

    it('refuses while the document text is not extracted', function () {
        Queue::fake();

        $document = CorpusDocument::factory()->create();

        $this->actingAs($this->admin)
            ->post("/manage/corpus/{$document->id}/author")
            ->assertRedirect()
            ->assertSessionHas('error', 'لا يمكن توليد صفحة قبل اكتمال استخراج نص المستند.');

        Queue::assertNothingPushed();
    });

    it('refuses while an authoring run is already in flight', function () {
        Queue::fake();

        $document = CorpusDocument::factory()->ready()->create([
            'authoring_status' => CorpusDocument::AUTHORING_RUNNING,
        ]);

        $this->actingAs($this->admin)
            ->post("/manage/corpus/{$document->id}/author")
            ->assertRedirect()
            ->assertSessionHas('error', 'يوجد توليد قيد التنفيذ لهذا المستند بالفعل.');

        Queue::assertNothingPushed();
    });

    it('refuses while the daily budget is exhausted', function () {
        Queue::fake();

        $settings = app(AiSettings::class);
        $settings->daily_budget_usd = 0.0;
        $settings->save();

        $document = CorpusDocument::factory()->ready()->create();

        $this->actingAs($this->admin)
            ->post("/manage/corpus/{$document->id}/author")
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
        expect($document->refresh()->authoring_status)->toBeNull();
    });
});

describe('authoring job — new content', function () {
    it('creates an unpublished draft page linked back to the document', function () {
        PageAuthoringAgent::fake(["# دليل المكافآت الجديد\n\n## الشروط\n\n- شرط أول\n- شرط ثانٍ"]);

        $document = CorpusDocument::factory()->ready()->create(['title' => 'تعميم المكافآت']);

        $document = runAuthoringJob($document);

        $page = Page::query()->where('title', 'دليل المكافآت الجديد')->first();

        expect($page)->not->toBeNull()
            ->and($page->hidden)->toBeTrue()
            ->and($page->parent_id)->toBeNull()
            ->and($page->html_content)->toBeArray()
            ->and(json_encode($page->html_content, JSON_UNESCAPED_UNICODE))->toContain('الشروط')
            ->and($document->authoring_status)->toBe(CorpusDocument::AUTHORING_DONE)
            ->and($document->authored_page_id)->toBe($page->id);

        PageAuthoringAgent::assertPrompted(
            fn (AgentPrompt $prompt): bool => $prompt->model === 'deepseek/deepseek-v4-pro'
                && $prompt->contains('تعميم المكافآت')
        );
    });

    it('records the spend under the authoring feature', function () {
        PageAuthoringAgent::fake(["# صفحة\n\nمحتوى."]);

        runAuthoringJob(CorpusDocument::factory()->ready()->create());

        expect(AiUsage::query()->where('feature', 'authoring')->count())->toBe(1);
    });

    it('falls back to the document title when the draft has no leading heading', function () {
        PageAuthoringAgent::fake(['فقرة بدون عنوان في أولها.']);

        $document = runAuthoringJob(CorpusDocument::factory()->ready()->create(['title' => 'لائحة بدون عنوان']));

        expect(Page::query()->where('title', 'لائحة بدون عنوان')->exists())->toBeTrue()
            ->and($document->authoring_status)->toBe(CorpusDocument::AUTHORING_DONE);
    });
});

describe('authoring job — updating an existing page', function () {
    it('stores a pending proposal without touching the live page', function () {
        $page = seedCandidatePage('الخطة الدراسية', 'تحتوي الخطة الدراسية على مقررات البرمجة وهياكل البيانات');
        $originalContent = $page->refresh()->html_content;

        PageAuthoringAgent::fake([
            json_encode(['decision' => 'update', 'page_id' => $page->id]),
            "## الخطة الدراسية المحدثة\n\nأضيفت مقررات جديدة.",
        ]);

        $document = CorpusDocument::factory()->ready()->create([
            'title' => 'تحديث الخطة الدراسية',
            'extracted_markdown' => '## الخطة الدراسية — نسخة محدثة'."\n\n".'أضيفت مقررات البرمجة المتقدمة إلى الخطة الدراسية.',
        ]);

        $document = runAuthoringJob($document);

        $proposal = PageContentProposal::query()->first();

        expect($proposal)->not->toBeNull()
            ->and($proposal->page_id)->toBe($page->id)
            ->and($proposal->corpus_document_id)->toBe($document->id)
            ->and($proposal->status)->toBe(PageContentProposal::STATUS_PENDING)
            ->and($proposal->proposed_markdown)->toContain('الخطة الدراسية المحدثة')
            ->and($proposal->proposed_html_content)->toBeArray()
            ->and($page->refresh()->html_content)->toBe($originalContent)
            ->and($document->authoring_status)->toBe(CorpusDocument::AUTHORING_DONE)
            ->and($document->authored_page_id)->toBeNull();
    });

    it('warns about custom blocks in the proposal summary', function () {
        $page = seedCandidatePage('الخطة الدراسية', 'تحتوي الخطة الدراسية على مقررات البرمجة وهياكل البيانات');
        $content = $page->refresh()->html_content;
        $content['content'][] = ['type' => 'customBlock', 'attrs' => ['id' => 'alert', 'config' => ['content' => '<p>تنبيه مهم</p>']]];
        $page->update(['html_content' => $content]);

        PageAuthoringAgent::fake([
            json_encode(['decision' => 'update', 'page_id' => $page->id]),
            "## محتوى معدل\n\nنص جديد.",
        ]);

        runAuthoringJob(CorpusDocument::factory()->ready()->create([
            'title' => 'تحديث الخطة الدراسية',
            'extracted_markdown' => '## الخطة الدراسية'."\n\n".'مستجدات الخطة الدراسية ومقررات البرمجة.',
        ]));

        expect(PageContentProposal::query()->first()->summary)->toContain('مكوّنات مخصّصة');
    });

    it('creates a new page when the model decides the document is new despite candidates', function () {
        seedCandidatePage('الخطة الدراسية', 'تحتوي الخطة الدراسية على مقررات البرمجة وهياكل البيانات');

        PageAuthoringAgent::fake([
            json_encode(['decision' => 'new']),
            "# دليل مختلف تماماً\n\nمحتوى جديد.",
        ]);

        $document = runAuthoringJob(CorpusDocument::factory()->ready()->create([
            'title' => 'الخطة الدراسية الجديدة',
            'extracted_markdown' => '## الخطة الدراسية'."\n\n".'نص عن الخطة الدراسية ومقررات البرمجة.',
        ]));

        expect(Page::query()->where('title', 'دليل مختلف تماماً')->exists())->toBeTrue()
            ->and(PageContentProposal::query()->count())->toBe(0)
            ->and($document->authoring_status)->toBe(CorpusDocument::AUTHORING_DONE);
    });

    it('marks authoring failed when the model returns an unparseable decision', function () {
        seedCandidatePage('الخطة الدراسية', 'تحتوي الخطة الدراسية على مقررات البرمجة وهياكل البيانات');

        PageAuthoringAgent::fake(['قرار غير صالح بدون JSON']);

        $document = runAuthoringJob(CorpusDocument::factory()->ready()->create([
            'title' => 'الخطة الدراسية',
            'extracted_markdown' => '## الخطة الدراسية'."\n\n".'نص عن مقررات البرمجة في الخطة الدراسية.',
        ]));

        expect($document->authoring_status)->toBe(CorpusDocument::AUTHORING_FAILED)
            ->and($document->authoring_error)->toBe('أعاد النموذج قراراً غير صالح — حاول مرة أخرى.')
            ->and(PageContentProposal::query()->count())->toBe(0);
    });
});

describe('authoring job — refusals', function () {
    it('fails without prompting while the copilot feature is disabled', function () {
        PageAuthoringAgent::fake(['يجب ألا يُستدعى']);
        disablePageAuthoring();

        $document = runAuthoringJob(CorpusDocument::factory()->ready()->create());

        expect($document->authoring_status)->toBe(CorpusDocument::AUTHORING_FAILED)
            ->and($document->authoring_error)->toBe('مساعد الكتابة الذكي معطل من إعدادات الذكاء الاصطناعي.');

        PageAuthoringAgent::assertNeverPrompted();
    });

    it('fails without prompting while the daily budget is exhausted', function () {
        PageAuthoringAgent::fake(['يجب ألا يُستدعى']);

        $settings = app(AiSettings::class);
        $settings->daily_budget_usd = 0.0;
        $settings->save();

        $document = runAuthoringJob(CorpusDocument::factory()->ready()->create());

        expect($document->authoring_status)->toBe(CorpusDocument::AUTHORING_FAILED)
            ->and($document->authoring_error)->not->toBeNull();

        PageAuthoringAgent::assertNeverPrompted();
    });

    it('fails without prompting while the document is not extracted', function () {
        PageAuthoringAgent::fake(['يجب ألا يُستدعى']);

        $document = runAuthoringJob(CorpusDocument::factory()->create());

        expect($document->authoring_status)->toBe(CorpusDocument::AUTHORING_FAILED)
            ->and($document->authoring_error)->toBe('لا يمكن توليد صفحة قبل اكتمال استخراج نص المستند.');

        PageAuthoringAgent::assertNeverPrompted();
    });
});

describe('proposal review screen', function () {
    it('renders the proposed content next to the current page content', function () {
        $page = Page::factory()->create([
            'title' => 'الخطة الدراسية',
            'html_content' => [
                'type' => 'doc',
                'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'المحتوى الحالي للصفحة']]]],
            ],
        ]);

        $proposal = PageContentProposal::factory()->create([
            'page_id' => $page->id,
            'proposed_markdown' => "## قسم مقترح\n\nمحتوى مقترح.",
        ]);

        $this->actingAs($this->admin)
            ->get("/manage/corpus/proposals/{$proposal->id}")
            ->assertSuccessful()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('manage/corpus/ProposalReview')
                ->where('proposal.id', $proposal->id)
                ->where('proposal.status', PageContentProposal::STATUS_PENDING)
                ->where('proposal.proposed_markdown', "## قسم مقترح\n\nمحتوى مقترح.")
                ->where('proposal.page.title', 'الخطة الدراسية')
                ->where('proposal.page.current_markdown', 'المحتوى الحالي للصفحة')
            );
    });

    it('redirects guests to the panel login', function () {
        $proposal = PageContentProposal::factory()->create();

        $this->get("/manage/corpus/proposals/{$proposal->id}")->assertRedirect(route('manage.login'));
    });
});

describe('applying a proposal', function () {
    it('updates the page through eloquent so the model-event cache flush fires', function () {
        $page = Page::factory()->create([
            'html_content' => [
                'type' => 'doc',
                'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'قديم']]]],
            ],
        ]);

        $proposal = PageContentProposal::factory()->create(['page_id' => $page->id]);

        Cache::put(config('app-cache.keys.navigation_tree'), ['stale']);

        $this->actingAs($this->admin)
            ->post("/manage/corpus/proposals/{$proposal->id}/apply")
            ->assertRedirect(route('manage.pages.edit', $page))
            ->assertSessionHas('success');

        expect($page->refresh()->html_content)->toBe($proposal->proposed_html_content)
            ->and(Cache::has(config('app-cache.keys.navigation_tree')))->toBeFalse()
            ->and($proposal->refresh()->status)->toBe(PageContentProposal::STATUS_APPLIED)
            ->and($proposal->applied_at)->not->toBeNull();
    });

    it('preserves customBlock nodes byte-identical by appending them after the revised content', function () {
        $customBlock = [
            'type' => 'customBlock',
            'attrs' => ['id' => 'alert', 'config' => ['content' => '<p>تنبيه <img src="/a.png" alt="صورة"></p>'], 'label' => 'تنبيه'],
        ];

        $page = Page::factory()->create([
            'html_content' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'قديم']]],
                    $customBlock,
                ],
            ],
        ]);

        $proposal = PageContentProposal::factory()->create(['page_id' => $page->id]);

        $this->actingAs($this->admin)
            ->post("/manage/corpus/proposals/{$proposal->id}/apply")
            ->assertRedirect(route('manage.pages.edit', $page));

        $content = $page->refresh()->html_content;

        expect(end($content['content']))->toBe($customBlock)
            ->and(array_slice($content['content'], 0, -1))->toBe($proposal->proposed_html_content['content']);
    });

    it('marks the proposal failed when the target page was trashed', function () {
        $proposal = PageContentProposal::factory()->create();
        $proposal->page->delete();

        $this->actingAs($this->admin)
            ->post("/manage/corpus/proposals/{$proposal->id}/apply")
            ->assertRedirect()
            ->assertSessionHas('error');

        expect($proposal->refresh()->status)->toBe(PageContentProposal::STATUS_FAILED)
            ->and($proposal->error)->toContain('لم تعد موجودة');
    });

    it('refuses to apply a proposal that is no longer pending', function () {
        $proposal = PageContentProposal::factory()->rejected()->create();
        $originalContent = $proposal->page->html_content;

        $this->actingAs($this->admin)
            ->post("/manage/corpus/proposals/{$proposal->id}/apply")
            ->assertRedirect()
            ->assertSessionHas('error', 'هذا الاقتراح لم يعد بانتظار المراجعة.');

        expect($proposal->refresh()->status)->toBe(PageContentProposal::STATUS_REJECTED)
            ->and($proposal->page->refresh()->html_content)->toBe($originalContent);
    });

    it('returns 403 while the admin copilot feature is disabled', function () {
        disablePageAuthoring();

        $proposal = PageContentProposal::factory()->create();

        $this->actingAs($this->admin)
            ->post("/manage/corpus/proposals/{$proposal->id}/apply")
            ->assertForbidden();

        expect($proposal->refresh()->status)->toBe(PageContentProposal::STATUS_PENDING);
    });
});

describe('rejecting a proposal', function () {
    it('marks the proposal rejected and never touches the page', function () {
        $proposal = PageContentProposal::factory()->create();
        $originalContent = $proposal->page->html_content;

        $this->actingAs($this->admin)
            ->post("/manage/corpus/proposals/{$proposal->id}/reject")
            ->assertRedirect(route('manage.corpus.edit', $proposal->corpus_document_id))
            ->assertSessionHas('success');

        expect($proposal->refresh()->status)->toBe(PageContentProposal::STATUS_REJECTED)
            ->and($proposal->page->refresh()->html_content)->toBe($originalContent);
    });

    it('refuses to reject a proposal that is no longer pending', function () {
        $proposal = PageContentProposal::factory()->applied()->create();

        $this->actingAs($this->admin)
            ->post("/manage/corpus/proposals/{$proposal->id}/reject")
            ->assertRedirect()
            ->assertSessionHas('error');

        expect($proposal->refresh()->status)->toBe(PageContentProposal::STATUS_APPLIED);
    });
});

describe('corpus screens expose authoring state', function () {
    it('lists authoring status and outcome links on the index', function () {
        $page = Page::factory()->create(['title' => 'مسودة مولدة']);

        CorpusDocument::factory()->ready()->create([
            'authoring_status' => CorpusDocument::AUTHORING_DONE,
            'authored_page_id' => $page->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/manage/corpus')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('manage/corpus/Index')
                ->where('documents.data.0.authoring_status', CorpusDocument::AUTHORING_DONE)
                ->where('documents.data.0.authored_page.title', 'مسودة مولدة')
                ->where('authoring.enabled', true)
            );
    });

    it('exposes the disabled reason while the feature is off', function () {
        disablePageAuthoring();

        $this->actingAs($this->admin)
            ->get('/manage/corpus')
            ->assertSuccessful()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->where('authoring.enabled', false)
                ->where('authoring.disabled_reason', 'مساعد الكتابة الذكي معطل. فعّله من صفحة الإعدادات لتوليد الصفحات من المستندات.')
            );
    });
});
