<?php

use App\Settings\AiSettings;
use Database\Factories\PageFactory;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the assistant page without a backing page using fallback seo', function () {
    $response = $this->get('/almosaed');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('AssistantPage')
        ->where('page', null)
        ->where('hasContent', false)
        ->where('disclaimer', (string) config('ai.assistant.disclaimer'))
        ->has('seo')
        ->where('seo.title', 'المساعد الذكي')
    );
});

it('renders the assistant page with its backing page record', function () {
    PageFactory::new()->create([
        'slug' => '/almosaed',
        'title' => 'المساعد الذكي',
    ]);

    $response = $this->get('/almosaed');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('AssistantPage')
        ->where('page.title', 'المساعد الذكي')
        ->where('hasContent', true)
        ->has('seo')
    );
});

it('still renders the assistant page when the assistant feature is disabled', function () {
    $settings = app(AiSettings::class);
    $settings->ai_enabled = false;
    $settings->assistant_enabled = false;
    $settings->save();

    $response = $this->get('/almosaed');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('AssistantPage'));
});
