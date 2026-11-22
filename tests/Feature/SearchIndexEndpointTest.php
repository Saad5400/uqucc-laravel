<?php

use Database\Factories\PageFactory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The quick-search index is its own browser-cacheable JSON request, made by
| the search bar on first use, instead of a ~100 KB prop on every page.
*/

it('serves the search index as cacheable JSON without a session', function (): void {
    Config::set('session.driver', 'database');
    PageFactory::new()->create(['slug' => '/altkhssat', 'title' => 'التخصصات']);

    $response = $this->getJson('/search-index.json');

    $response->assertOk()
        ->assertJsonFragment(['slug' => '/altkhssat', 'title' => 'التخصصات'])
        ->assertHeader('ETag');

    expect($response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=300')
        ->and($response->headers->getCookies())->toBe([])
        ->and(DB::table('sessions')->count())->toBe(0);

    $this->getJson('/search-index.json', ['If-None-Match' => $response->headers->get('ETag')])
        ->assertStatus(304);
});

it('no longer ships the search index in every page\'s props', function (): void {
    PageFactory::new()->create(['slug' => '/altkhssat', 'title' => 'التخصصات']);

    $this->get('/altkhssat')->assertInertia(fn (Assert $page) => $page->missing('searchData'));
});

it('hands a session-less visitor an XSRF token before their first POST', function (): void {
    $response = $this->get('/csrf-cookie');

    $response->assertNoContent();
    expect(collect($response->headers->getCookies())->map->getName())
        ->toContain('XSRF-TOKEN')
        ->toContain(config('session.cookie'));
});
