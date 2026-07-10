<?php

use App\Ai\Copilot\PageCopilotAgent;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Page;
use App\Models\User;
use App\Settings\AiSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
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

describe('pages resource copilot actions', function () {
    it('renders the edit page with the copilot actions when the feature is enabled', function () {
        setPageCopilotEnabled(true);
        $page = makeCopilotPage();

        $this->actingAs($this->admin)
            ->get(PageResource::getUrl('edit', ['record' => $page]))
            ->assertSuccessful()
            ->assertSee('تحسين النص')
            ->assertSee('مسودة قسم')
            ->assertSee('توليد وصف SEO');
    });

    it('hides the copilot actions entirely when the feature is disabled', function () {
        setPageCopilotEnabled(false);
        $page = makeCopilotPage();

        $this->actingAs($this->admin)
            ->get(PageResource::getUrl('edit', ['record' => $page]))
            ->assertSuccessful()
            ->assertDontSee('تحسين النص')
            ->assertDontSee('مسودة قسم')
            ->assertDontSee('توليد وصف SEO');
    });

    it('fills the editor with the improved content without saving', function () {
        setPageCopilotEnabled(true);
        $page = makeCopilotPage();

        PageCopilotAgent::fake(["## محسّن\n\nنص محسّن من المساعد."]);

        $this->actingAs($this->admin);

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->callFormComponentAction('html_content', 'improveText', data: ['instruction' => 'اجعله أوضح'])
            ->assertNotified('تم تحسين النص')
            ->assertSet('data.html_content', function ($state): bool {
                return is_array($state)
                    && str_contains((string) json_encode($state, JSON_UNESCAPED_UNICODE), 'نص محسّن من المساعد');
            });

        expect(json_encode($page->refresh()->html_content, JSON_UNESCAPED_UNICODE))
            ->not->toContain('نص محسّن من المساعد');
    });

    it('appends a drafted section after the existing content', function () {
        setPageCopilotEnabled(true);
        $page = makeCopilotPage();

        PageCopilotAgent::fake(["## قسم جديد\n\nمحتوى القسم المولد."]);

        $this->actingAs($this->admin);

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->callFormComponentAction('html_content', 'draftSection', data: ['topic' => 'قسم جديد'])
            ->assertNotified('تمت إضافة مسودة القسم')
            ->assertSet('data.html_content', function ($state): bool {
                $json = (string) json_encode($state, JSON_UNESCAPED_UNICODE);

                return str_contains($json, 'محتوى الصفحة الأصلي.') && str_contains($json, 'محتوى القسم المولد.');
            });
    });

    it('fills the quick response message with the generated seo description', function () {
        setPageCopilotEnabled(true);
        $page = makeCopilotPage();

        PageCopilotAgent::fake(['{"title": "تخصصات الكلية", "description": "تعرف على تخصصات كلية الحاسبات وشروطها."}']);

        $this->actingAs($this->admin);

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->callFormComponentAction('quick_response_message', 'generateSeoMeta')
            ->assertNotified('تم توليد وصف SEO')
            ->assertSet('data.quick_response_message', function ($state): bool {
                return str_contains((string) json_encode($state, JSON_UNESCAPED_UNICODE), 'تعرف على تخصصات كلية الحاسبات وشروطها.');
            });
    });

    it('shows an error notification when the generation fails', function () {
        setPageCopilotEnabled(true);
        $page = makeCopilotPage();

        PageCopilotAgent::fake(['ناتج ليس JSON']);

        $this->actingAs($this->admin);

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->callFormComponentAction('quick_response_message', 'generateSeoMeta')
            ->assertNotified('تعذر توليد وصف SEO');
    });

    it('does not register the copilot actions at all when the feature is disabled', function () {
        setPageCopilotEnabled(false);
        $page = makeCopilotPage();

        $this->actingAs($this->admin);

        Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
            ->assertFormComponentActionDoesNotExist('html_content', 'improveText')
            ->assertFormComponentActionDoesNotExist('html_content', 'draftSection')
            ->assertFormComponentActionDoesNotExist('quick_response_message', 'generateSeoMeta');
    });
});
