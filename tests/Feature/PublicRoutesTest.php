<?php

use App\Models\User;
use Database\Factories\PageFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

it('renders the welcome page when no homepage exists', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});

it('renders the homepage from the "/" page record', function () {
    PageFactory::new()->create([
        'slug' => '/',
        'title' => 'الرئيسية',
    ]);

    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('ContentPage')
        ->where('page.slug', '/')
    );
});

it('renders a content page by slug', function () {
    PageFactory::new()->create([
        'slug' => '/altkhssat',
        'title' => 'التخصصات',
    ]);

    $response = $this->get('/altkhssat');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('ContentPage')
        ->where('page.slug', '/altkhssat')
        ->where('page.title', 'التخصصات')
        ->has('breadcrumbs')
        ->has('seo')
    );
});

it('renders nested content pages with breadcrumbs up to the root', function () {
    $parent = PageFactory::new()->create(['slug' => '/allwaeh', 'title' => 'اللوائح']);
    PageFactory::new()->childOf($parent)->create(['slug' => '/allwaeh/alghyab', 'title' => 'الغياب']);

    $response = $this->get('/allwaeh/alghyab');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('ContentPage')
        ->count('breadcrumbs', 2)
        ->where('breadcrumbs.0.path', '/allwaeh')
        ->where('breadcrumbs.1.path', '/allwaeh/alghyab')
    );
});

it('returns 404 for a hidden content page', function () {
    PageFactory::new()->hidden()->create(['slug' => '/mkhfy']);

    $this->get('/mkhfy')->assertNotFound();
});

it('returns 404 for a missing content page', function () {
    $this->get('/ghyr-mwjwd')->assertNotFound();
});

it('renders each tool route with its backing page', function (string $uri, string $slug, string $component) {
    PageFactory::new()->create([
        'slug' => $slug,
        'title' => 'أداة تجريبية',
    ]);

    $response = $this->get($uri);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component($component)
        ->where('page.title', 'أداة تجريبية')
        ->where('hasContent', true)
        ->has('seo')
    );
})->with([
    'gpa calculator' => ['/adwat/hasbh-almadl', '/adwat/hasbh-almadl', 'tools/GpaCalculatorPage'],
    'deprivation calculator' => ['/adwat/hasbh-alhrman', '/adwat/hasbh-alhrman', 'tools/DeprivationCalculatorPage'],
    'transfer calculator' => ['/adwat/hasbh-altahwel', '/adwat/hasbh-altahwel', 'tools/TransferCalculatorPage'],
    'next reward' => ['/adwat/almkafa', '/adwat/almkafa', 'tools/NextRewardPage'],
]);

it('renders each tool route without a backing page using fallback seo', function (string $uri, string $component) {
    $response = $this->get($uri);

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component($component)
        ->where('page', null)
        ->where('hasContent', false)
        ->has('seo')
    );
})->with([
    'gpa calculator' => ['/adwat/hasbh-almadl', 'tools/GpaCalculatorPage'],
    'deprivation calculator' => ['/adwat/hasbh-alhrman', 'tools/DeprivationCalculatorPage'],
    'transfer calculator' => ['/adwat/hasbh-altahwel', 'tools/TransferCalculatorPage'],
    'next reward' => ['/adwat/almkafa', 'tools/NextRewardPage'],
]);

it('renders the private tutors tool route', function () {
    $this->get('/adwat/alkhosousieen')->assertOk();
});

it('permanently redirects the old admin panel to /manage', function (string $from) {
    $this->get($from)->assertMovedPermanently()->assertRedirect(url('/manage'));
})->with([
    'panel root' => ['/admin'],
    'panel login' => ['/admin/login'],
    'deep resource url' => ['/admin/pages/5/edit'],
]);

it('serves robots.txt as plain text', function () {
    $response = $this->get('/robots.txt');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    expect($response->getContent())
        ->toContain('User-agent: *')
        ->toContain('Disallow: /admin')
        ->toContain('Sitemap: ');
});

it('shares the manage edit url with users who can edit content', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $page = PageFactory::new()->create(['slug' => '/altkhssat', 'title' => 'التخصصات']);

    $editor = User::factory()->create();
    $editor->assignRole('editor');

    $this->actingAs($editor)->get('/altkhssat')->assertInertia(fn (Assert $assert) => $assert
        ->where('page.can_edit', true)
        ->where('page.edit_url', route('manage.pages.edit', $page))
    );

    expect(route('manage.pages.edit', $page))->toContain("/manage/pages/{$page->id}/edit");

    // Pins the bot's EditLinkHandler swap: route() must yield the same
    // absolute-URL shape url('/manage/pages/{id}/edit') produced.
    expect(route('manage.pages.edit', $page))->toBe(url("/manage/pages/{$page->id}/edit"));
});

it('hides the edit url from guests', function () {
    PageFactory::new()->create(['slug' => '/altkhssat', 'title' => 'التخصصات']);

    $this->get('/altkhssat')->assertInertia(fn (Assert $assert) => $assert
        ->where('page.can_edit', false)
        ->where('page.edit_url', null)
    );
});

it('serves cached responses to guests on repeated visits', function () {
    PageFactory::new()->create(['slug' => '/altkhssat', 'title' => 'التخصصات']);

    $this->get('/altkhssat')->assertOk();
    $second = $this->get('/altkhssat');

    $second->assertOk();
    $second->assertHeader('X-Cache', 'HIT');
});
