<?php

use App\Ai\Copilot\PageCopilotAgent;
use App\Models\Page;
use App\Models\User;
use App\Settings\AiSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    config()->set('ai.embeddings.driver', 'fake');
});

function setPageCopilotEnabled(bool $enabled): void
{
    $settings = app(AiSettings::class);
    $settings->ai_enabled = $enabled;
    $settings->admin_copilot_enabled = $enabled;
    $settings->save();

    config()->set('ai.providers.openrouter.key', $enabled ? 'test-key' : '');
}

function makeCopilotPage(): Page
{
    return Page::factory()->create([
        'title' => 'التخصصات',
        'html_content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'محتوى الصفحة الأصلي.']]],
            ],
        ],
    ]);
}

describe('page workspace copilot endpoints', function () {
    it('shares the copilot as enabled with the page workspace when the feature is on', function () {
        setPageCopilotEnabled(true);
        $page = makeCopilotPage();

        $this->actingAs($this->admin)
            ->get("/manage/pages/{$page->id}/edit")
            ->assertSuccessful()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('manage/pages/Edit')
                ->where('copilot.enabled', true)
            );
    });

    it('shares the copilot as disabled (hiding the actions entirely) when the feature is off', function () {
        setPageCopilotEnabled(false);
        $page = makeCopilotPage();

        $this->actingAs($this->admin)
            ->get("/manage/pages/{$page->id}/edit")
            ->assertSuccessful()
            ->assertInertia(fn (Assert $inertia) => $inertia
                ->component('manage/pages/Edit')
                ->where('copilot.enabled', false)
            );
    });

    it('returns the improved content without saving it', function () {
        setPageCopilotEnabled(true);
        $page = makeCopilotPage();

        PageCopilotAgent::fake(["## محسّن\n\nنص محسّن من المساعد."]);

        $response = $this->actingAs($this->admin)
            ->postJson("/manage/pages/{$page->id}/copilot/improve-text", [
                'content' => $page->html_content,
                'instruction' => 'اجعله أوضح',
            ]);

        $response->assertSuccessful();

        $content = $response->json('content');

        expect($content)->toBeArray()
            ->and(json_encode($content, JSON_UNESCAPED_UNICODE))->toContain('نص محسّن من المساعد');

        expect(json_encode($page->refresh()->html_content, JSON_UNESCAPED_UNICODE))
            ->not->toContain('نص محسّن من المساعد');
    });

    it('rejects improving an empty page with an Arabic message', function () {
        setPageCopilotEnabled(true);
        $page = makeCopilotPage();

        $response = $this->actingAs($this->admin)
            ->postJson("/manage/pages/{$page->id}/copilot/improve-text", ['content' => null])
            ->assertUnprocessable();

        expect($response->json('message'))->toContain('لا يوجد محتوى لتحسينه');
    });

    it('returns a drafted section appended after the existing content', function () {
        setPageCopilotEnabled(true);
        $page = makeCopilotPage();

        PageCopilotAgent::fake(["## قسم جديد\n\nمحتوى القسم المولد."]);

        $response = $this->actingAs($this->admin)
            ->postJson("/manage/pages/{$page->id}/copilot/draft-section", [
                'content' => $page->html_content,
                'topic' => 'قسم جديد',
            ]);

        $response->assertSuccessful();

        $json = (string) json_encode($response->json('content'), JSON_UNESCAPED_UNICODE);

        expect($json)->toContain('محتوى الصفحة الأصلي.')
            ->and($json)->toContain('محتوى القسم المولد.');
    });

    it('requires a topic for the drafted section', function () {
        setPageCopilotEnabled(true);
        $page = makeCopilotPage();

        $this->actingAs($this->admin)
            ->postJson("/manage/pages/{$page->id}/copilot/draft-section", ['content' => $page->html_content])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['topic' => 'حقل موضوع القسم مطلوب.']);
    });

    it('returns the generated seo description as the quick response message html', function () {
        setPageCopilotEnabled(true);
        $page = makeCopilotPage();

        PageCopilotAgent::fake(['{"title": "تخصصات الكلية", "description": "تعرف على تخصصات كلية الحاسبات وشروطها."}']);

        $this->actingAs($this->admin)
            ->postJson("/manage/pages/{$page->id}/copilot/seo-meta")
            ->assertSuccessful()
            ->assertJson([
                'title' => 'تخصصات الكلية',
                'message' => '<p>تعرف على تخصصات كلية الحاسبات وشروطها.</p>',
            ]);

        expect((string) $page->refresh()->quick_response_message)
            ->not->toContain('تعرف على تخصصات كلية الحاسبات وشروطها.');
    });

    it('returns an error with the Arabic message when the generation fails', function () {
        setPageCopilotEnabled(true);
        $page = makeCopilotPage();

        PageCopilotAgent::fake(['ناتج ليس JSON']);

        $this->actingAs($this->admin)
            ->postJson("/manage/pages/{$page->id}/copilot/seo-meta")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'أعاد النموذج ناتجاً غير صالح لوصف SEO — حاول مرة أخرى.');
    });

    it('rejects every endpoint with 403 when the feature is disabled', function (string $uri, array $payload) {
        setPageCopilotEnabled(false);
        $page = makeCopilotPage();

        $this->actingAs($this->admin)
            ->postJson("/manage/pages/{$page->id}/copilot/{$uri}", $payload)
            ->assertForbidden()
            ->assertJsonPath('message', 'مساعد الكتابة الذكي معطل من إعدادات الذكاء الاصطناعي.');
    })->with([
        'improve text' => ['improve-text', ['content' => ['type' => 'doc', 'content' => []]]],
        'draft section' => ['draft-section', ['topic' => 'موضوع']],
        'seo meta' => ['seo-meta', []],
    ]);

    it('rejects guests', function () {
        setPageCopilotEnabled(true);
        $page = makeCopilotPage();

        $this->postJson("/manage/pages/{$page->id}/copilot/seo-meta")->assertUnauthorized();
    });
});
