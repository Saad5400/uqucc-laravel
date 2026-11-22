<?php

use App\Models\Page;
use App\Models\PageViewStat;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

it('truncates an over-long user agent to the column width instead of throwing', function () {
    $page = Page::factory()->create();

    // Real Instagram in-app browser UAs run well past varchar(255).
    $userAgent = 'Mozilla/5.0 (Linux; Android 12; '.str_repeat('CET-LX9 Build/HUAWEICET-L29; ', 20).') Instagram 432.1.0.44.80';

    expect(mb_strlen($userAgent))->toBeGreaterThan(255);

    PageViewStat::track(
        pageId: $page->id,
        userId: null,
        ipAddress: '178.73.100.89',
        userAgent: $userAgent,
    );

    $stat = PageViewStat::query()->where('page_id', $page->id)->sole();

    expect(mb_strlen((string) $stat->user_agent))->toBe(255)
        ->and($userAgent)->toStartWith((string) $stat->user_agent);
});

it('stores a normal-length user agent verbatim', function () {
    $page = Page::factory()->create();
    $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36';

    PageViewStat::track($page->id, null, '10.0.0.1', $userAgent);

    expect(PageViewStat::query()->where('page_id', $page->id)->sole()->user_agent)
        ->toBe($userAgent);
});

it('keeps a null user agent null', function () {
    $page = Page::factory()->create();

    PageViewStat::track($page->id, null, '10.0.0.2', null);

    expect(PageViewStat::query()->where('page_id', $page->id)->sole()->user_agent)
        ->toBeNull();
});

it('counts repeat views of one identity on one row with a single upsert', function () {
    $page = Page::factory()->create();

    $this->travelTo(now()->subHour());
    PageViewStat::track($page->id, null, '10.0.0.3', 'UA one');
    $this->travelBack();
    PageViewStat::track($page->id, null, '10.0.0.3', 'UA two');

    $stat = PageViewStat::query()->where('page_id', $page->id)->sole();

    expect($stat->view_count)->toBe(2)
        ->and($stat->user_agent)->toBe('UA one')
        ->and($stat->last_viewed_at->diffInSeconds(now()))->toBeLessThan(5);
});

it('keeps guests, users and pages apart', function () {
    $page = Page::factory()->create();
    $other = Page::factory()->create();
    $user = User::factory()->create();

    PageViewStat::track($page->id, null, '10.0.0.4');
    PageViewStat::track($page->id, null, '10.0.0.5');
    PageViewStat::track($page->id, $user->id, '10.0.0.4');
    PageViewStat::track($other->id, null, '10.0.0.4');
    PageViewStat::track($page->id, null, '10.0.0.4');

    expect(PageViewStat::query()->count())->toBe(4)
        ->and(PageViewStat::query()->where(['page_id' => $page->id, 'ip_address' => '10.0.0.4'])->whereNull('user_id')->value('view_count'))->toBe(2);
});

it('never moves last_viewed_at backwards when adding older views', function () {
    $page = Page::factory()->create();

    PageViewStat::track($page->id, null, '10.0.0.6');
    PageViewStat::track($page->id, null, '10.0.0.6', views: 3, viewedAt: now()->subDays(10));

    $stat = PageViewStat::query()->sole();

    expect($stat->view_count)->toBe(4)
        ->and($stat->last_viewed_at->diffInSeconds(now()))->toBeLessThan(5);
});

it('folds a deleted user\'s views into the guest rows for the same ip', function () {
    $page = Page::factory()->create();
    $user = User::factory()->create();

    PageViewStat::track($page->id, null, '10.0.0.7', views: 2);
    PageViewStat::track($page->id, $user->id, '10.0.0.7', views: 3);
    PageViewStat::track($page->id, $user->id, '10.0.0.8', views: 1);

    $user->delete();

    expect(PageViewStat::query()->whereNotNull('user_id')->count())->toBe(0)
        ->and(PageViewStat::query()->orderBy('ip_address')->pluck('view_count', 'ip_address')->all())
        ->toBe(['10.0.0.7' => 5, '10.0.0.8' => 1])
        ->and((int) PageViewStat::query()->sum('view_count'))->toBe(6);
});

it('merges rows that already share an identity before adding the unique index', function () {
    $migration = require database_path('migrations/2026_11_22_000001_add_identity_unique_index_to_page_view_stats_table.php');
    $page = Page::factory()->create();
    $migration->down();

    $row = fn (?string $ip, int $views, string $lastViewed, string $created): array => [
        'page_id' => $page->id, 'user_id' => null, 'ip_address' => $ip, 'user_agent' => null,
        'view_count' => $views, 'last_viewed_at' => $lastViewed, 'created_at' => $created, 'updated_at' => $created,
    ];
    DB::table('page_view_stats')->insert([
        $row('10.0.0.9', 2, '2026-11-01 10:00:00', '2026-10-01 10:00:00'),
        $row('10.0.0.9', 3, '2026-11-05 10:00:00', '2026-10-05 10:00:00'),
        $row(null, 1, '2026-11-02 10:00:00', '2026-10-02 10:00:00'),
        $row(null, 4, '2026-11-03 10:00:00', '2026-10-03 10:00:00'),
        $row('10.0.0.10', 7, '2026-11-04 10:00:00', '2026-10-04 10:00:00'),
    ]);

    $migration->up();

    $rows = PageViewStat::query()->orderBy('id')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->map(fn (PageViewStat $stat): array => [
            $stat->ip_address, $stat->view_count, $stat->last_viewed_at->toDateTimeString(), $stat->created_at->toDateTimeString(),
        ])->all())->toBe([
            ['10.0.0.9', 5, '2026-11-05 10:00:00', '2026-10-01 10:00:00'],
            [null, 5, '2026-11-03 10:00:00', '2026-10-02 10:00:00'],
            ['10.0.0.10', 7, '2026-11-04 10:00:00', '2026-10-04 10:00:00'],
        ]);

    // And from now on the database itself refuses a second row per identity.
    expect(fn () => DB::table('page_view_stats')->insert($row('10.0.0.10', 1, '2026-11-06 10:00:00', '2026-11-06 10:00:00')))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('folds more than a thousand overlapping rows when a user is deleted', function () {
    $page = Page::factory()->create();
    $user = User::factory()->create();
    $now = now()->toDateTimeString();
    $row = fn (?int $userId, int $i, int $views): array => [
        'page_id' => $page->id, 'user_id' => $userId, 'ip_address' => "10.1.{$i}", 'user_agent' => null,
        'view_count' => $views, 'last_viewed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ];

    foreach (array_chunk(range(1, 1100), 200) as $chunk) {
        DB::table('page_view_stats')->insert([
            ...array_map(fn (int $i): array => $row(null, $i, 2), $chunk),
            ...array_map(fn (int $i): array => $row($user->id, $i, 3), $chunk),
        ]);
    }

    $user->delete();

    expect(User::query()->whereKey($user->id)->exists())->toBeFalse()
        ->and(PageViewStat::query()->count())->toBe(1100)
        ->and(PageViewStat::query()->whereNotNull('user_id')->count())->toBe(0)
        ->and((int) PageViewStat::query()->sum('view_count'))->toBe(1100 * 5)
        ->and(PageViewStat::query()->where('view_count', '!=', 5)->count())->toBe(0);
});

it('degrades to not counting, logged once, when the identity index is missing', function () {
    $migration = require database_path('migrations/2026_11_22_000001_add_identity_unique_index_to_page_view_stats_table.php');
    $page = Page::factory()->create();
    $user = User::factory()->create();
    PageViewStat::factory()->create(['page_id' => $page->id, 'user_id' => $user->id, 'ip_address' => '10.0.0.11', 'view_count' => 2]);
    $migration->down();
    Log::spy();

    PageViewStat::track($page->id, null, '10.0.0.12');
    PageViewStat::track($page->id, null, '10.0.0.12');

    // A user can still be deleted: ON DELETE SET NULL keeps their views as before.
    $user->delete();

    expect(PageViewStat::query()->pluck('view_count', 'ip_address')->all())->toBe(['10.0.0.11' => 2]);
    Log::shouldHaveReceived('error')->once();
});
