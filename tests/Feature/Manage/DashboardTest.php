<?php

use App\Models\BotCommandStat;
use App\Models\Page;
use App\Models\PageViewStat;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

describe('authorization', function () {
    it('redirects guests to the login page', function () {
        $this->get('/manage')->assertRedirect(route('manage.login'));
    });

    it('allows editors to open the dashboard', function () {
        $this->actingAs($this->editor)->get('/manage')->assertOk();
    });
});

describe('stats', function () {
    it('reports page, view, and bot stats against seeded data', function () {
        $rootOne = Page::factory()->create();
        $rootTwo = Page::factory()->create();
        Page::factory()->childOf($rootOne)->create();

        $this->admin->pages()->attach($rootOne, ['order' => 1]);
        $this->admin->pages()->attach($rootTwo, ['order' => 1]);
        $this->editor->pages()->attach($rootOne, ['order' => 2]);

        PageViewStat::factory()->create(['page_id' => $rootOne->id, 'view_count' => 5, 'ip_address' => '10.0.0.1', 'last_viewed_at' => now()->subDays(2)]);
        PageViewStat::factory()->create(['page_id' => $rootTwo->id, 'view_count' => 3, 'ip_address' => '10.0.0.2', 'last_viewed_at' => now()]);
        PageViewStat::factory()->create(['page_id' => $rootTwo->id, 'view_count' => 7, 'ip_address' => '10.0.0.3', 'last_viewed_at' => now()->subDays(40)]);

        BotCommandStat::factory()->create(['command_name' => '/start', 'count' => 4, 'last_used_at' => now()->subDay()]);
        BotCommandStat::factory()->create(['command_name' => '/help', 'count' => 2, 'last_used_at' => now()]);
        BotCommandStat::factory()->create(['command_name' => '/legacy', 'count' => 10, 'last_used_at' => now()->subDays(60)]);

        $response = $this->actingAs($this->admin)->get('/manage');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('manage/Dashboard')
            ->where('stats.totalPages', 3)
            ->where('stats.rootPages', 2)
            ->where('stats.contributors', 2)
            ->where('stats.views30d', 8)
            ->where('stats.uniqueVisitors30d', 2)
            ->where('stats.botUses30d', 6)
            ->where('stats.topCommand.name', '/legacy')
            ->where('stats.topCommand.uses', 10));
    });

    it('reports empty stats without data', function () {
        $response = $this->actingAs($this->admin)->get('/manage');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('stats.totalPages', 0)
            ->where('stats.views30d', 0)
            ->where('stats.topCommand', null));
    });
});

describe('deferred props', function () {
    it('defers the charts and lists out of the initial load', function () {
        $response = $this->actingAs($this->admin)->get('/manage');

        $response->assertInertia(fn (Assert $page) => $page
            ->has('stats')
            ->missing('viewsChart')
            ->missing('commandsChart')
            ->missing('latestPages')
            ->missing('mostViewed')
            ->missing('topCommands'));

        $deferred = $response->viewData('page')['deferredProps']['default'] ?? [];

        expect($deferred)->toEqualCanonicalizing(['viewsChart', 'commandsChart', 'latestPages', 'mostViewed', 'topCommands']);
    });

    it('resolves the deferred props on a partial reload', function () {
        $page = Page::factory()->create(['title' => 'دليل القبول']);
        PageViewStat::factory()->create(['page_id' => $page->id, 'view_count' => 5, 'last_viewed_at' => now()]);
        BotCommandStat::factory()->create(['command_name' => '/start', 'count' => 3, 'last_used_at' => now()]);

        $this->actingAs($this->admin)->get('/manage')->assertOk();

        $response = $this->actingAs($this->admin)->get('/manage', [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => \Inertia\Inertia::getVersion(),
            'X-Inertia-Partial-Component' => 'manage/Dashboard',
            'X-Inertia-Partial-Data' => 'viewsChart,commandsChart,latestPages,mostViewed,topCommands',
        ]);

        $response->assertOk()
            ->assertJsonPath('component', 'manage/Dashboard')
            ->assertJsonCount(30, 'props.viewsChart')
            ->assertJsonPath('props.viewsChart.29.count', 5)
            ->assertJsonPath('props.viewsChart.0.count', 0)
            ->assertJsonCount(30, 'props.commandsChart')
            ->assertJsonPath('props.commandsChart.29.count', 3)
            ->assertJsonCount(1, 'props.latestPages')
            ->assertJsonPath('props.latestPages.0.title', 'دليل القبول')
            ->assertJsonCount(1, 'props.mostViewed')
            ->assertJsonPath('props.mostViewed.0.id', $page->id)
            ->assertJsonPath('props.mostViewed.0.views', 5)
            ->assertJsonCount(1, 'props.topCommands')
            ->assertJsonPath('props.topCommands.0.command', '/start')
            ->assertJsonPath('props.topCommands.0.uses', 3);
    });
});

describe('cache clear', function () {
    it('clears the application cache and flashes success', function () {
        Cache::put('probe', 'value');

        $response = $this->actingAs($this->admin)->from('/manage')->post('/manage/cache/clear');

        $response->assertRedirect('/manage');
        $response->assertSessionHas('success', 'تم مسح الكاش');
        expect(Cache::get('probe'))->toBeNull();
    });

    it('redirects guests trying to clear the cache', function () {
        $this->post('/manage/cache/clear')->assertRedirect(route('manage.login'));
    });
});
