<?php

use Database\Factories\PageFactory;
use Inertia\Testing\AssertableInertia as Assert;

/*
| What a public page carries: no longer the whole search index (~100 KB in
| every page and every Inertia visit, read by a search bar that no longer
| exists — the visible search is the AI palette over /bahth), and — when it
| was served from the full-page cache before the session layer — no XSRF
| cookie, which the client fetches before its first POST.
*/

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
