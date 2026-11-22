<?php

use App\Models\Ai\PageContentProposal;
use App\Models\Page;
use App\Models\User;
use App\Settings\AiSettings;
use Database\Seeders\RolesAndPermissionsSeeder;

/*
 * A review-mode editor's page edits are captured as change requests; every
 * OTHER write to a live page must be refused for them, not silently applied.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->reviewEditor = User::factory()->create(['requires_review' => true]);
    $this->reviewEditor->assignRole('editor');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

it('refuses page structure changes from a review-mode editor', function (string $method, Closure $uri, Closure $payload, Closure $unchanged) {
    $page = Page::factory()->create(['title' => 'صفحة', 'order' => 1]);
    $sibling = Page::factory()->create(['title' => 'أخرى', 'order' => 2]);
    $trashed = Page::factory()->create(['title' => 'محذوفة']);
    $trashed->delete();

    $context = compact('page', 'sibling', 'trashed');

    $this->actingAs($this->reviewEditor)
        ->from('/manage/pages')
        ->{$method}($uri($context), $payload($context))
        ->assertRedirect('/manage/pages')
        ->assertSessionHas('error');

    expect($unchanged($context))->toBeTrue();
})->with([
    'create' => ['post', fn () => '/manage/pages', fn () => ['title' => 'صفحة جديدة'],
        fn () => Page::withTrashed()->count() === 3],
    'reorder' => ['post', fn () => '/manage/pages/reorder', fn ($c) => ['ids' => [$c['sibling']->id, $c['page']->id]],
        fn ($c) => $c['page']->fresh()->order === 1],
    'delete' => ['delete', fn ($c) => "/manage/pages/{$c['page']->id}", fn () => [],
        fn ($c) => ! $c['page']->fresh()->trashed()],
    'restore' => ['post', fn ($c) => "/manage/pages/{$c['trashed']->id}/restore", fn () => [],
        fn ($c) => $c['trashed']->fresh()->trashed()],
    'force delete' => ['delete', fn ($c) => "/manage/pages/{$c['trashed']->id}/force", fn () => [],
        fn ($c) => Page::withTrashed()->whereKey($c['trashed']->id)->exists()],
    'authors' => ['put', fn ($c) => "/manage/pages/{$c['page']->id}/authors", fn () => ['user_ids' => [User::query()->value('id')]],
        fn ($c) => $c['page']->users()->count() === 0],
]);

it('still lets a non-review editor delete a page', function () {
    $page = Page::factory()->create();

    $this->actingAs($this->editor)
        ->delete("/manage/pages/{$page->id}")
        ->assertRedirect();

    expect($page->fresh()->trashed())->toBeTrue();
});

it('refuses to apply a content proposal for a review-mode editor', function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->admin_copilot_enabled = true;
    $settings->daily_budget_usd = 5.0;
    $settings->save();
    config()->set('ai.providers.openrouter.key', 'test-key');

    $page = Page::factory()->create(['html_content' => 'قديم']);
    $proposal = PageContentProposal::factory()->create(['page_id' => $page->id]);

    $this->actingAs($this->reviewEditor)
        ->from('/manage/corpus')
        ->post("/manage/corpus/proposals/{$proposal->id}/apply")
        ->assertRedirect('/manage/corpus')
        ->assertSessionHas('error');

    expect($page->fresh()->getRawOriginal('html_content'))->toBe('قديم')
        ->and($proposal->fresh()->status)->toBe(PageContentProposal::STATUS_PENDING);
});

it('keeps review-mode editors off the Telegram page tools', function () {
    expect($this->reviewEditor->canManagePagesViaTelegram())->toBeFalse()
        ->and($this->editor->canManagePagesViaTelegram())->toBeTrue();
});
