<?php

use App\Models\User;
use Database\Factories\PageFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

it('redirects guests to the login page', function () {
    $page = PageFactory::new()->create();

    $this->put("/manage/pages/{$page->id}/authors", ['user_ids' => []])->assertRedirect(route('manage.login'));
});

it('syncs authors with the pivot order taken from the array position', function () {
    $page = PageFactory::new()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();

    $response = $this->actingAs($this->admin)->put("/manage/pages/{$page->id}/authors", [
        'user_ids' => [$second->id, $first->id],
    ]);

    $response->assertRedirect();

    $authors = $page->fresh()->users;
    expect($authors->pluck('id')->all())->toBe([$second->id, $first->id]);
    expect($authors->first()->pivot->order)->toBe(1);
    expect($authors->last()->pivot->order)->toBe(2);
});

it('reorders existing authors on a subsequent sync', function () {
    $page = PageFactory::new()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();

    $page->users()->attach([$first->id => ['order' => 1], $second->id => ['order' => 2]]);

    $this->actingAs($this->admin)->put("/manage/pages/{$page->id}/authors", [
        'user_ids' => [$second->id, $first->id],
    ]);

    expect($page->fresh()->users->pluck('id')->all())->toBe([$second->id, $first->id]);
});

it('detaches authors omitted from the list, including everyone on an empty list', function () {
    $page = PageFactory::new()->create();
    $author = User::factory()->create();
    $page->users()->attach($author, ['order' => 1]);

    $this->actingAs($this->admin)->put("/manage/pages/{$page->id}/authors", ['user_ids' => []]);

    expect($page->fresh()->users)->toHaveCount(0);
});

it('rejects unknown users and duplicate entries', function () {
    $page = PageFactory::new()->create();
    $author = User::factory()->create();

    $this->actingAs($this->admin)->put("/manage/pages/{$page->id}/authors", ['user_ids' => [999999]])
        ->assertSessionHasErrors('user_ids.0');

    $this->actingAs($this->admin)->put("/manage/pages/{$page->id}/authors", ['user_ids' => [$author->id, $author->id]])
        ->assertSessionHasErrors();
});

it('flushes the app caches by touching the page', function () {
    $page = PageFactory::new()->create();
    $author = User::factory()->create();

    Cache::put(config('app-cache.keys.navigation_tree'), ['stale']);

    $this->actingAs($this->admin)->put("/manage/pages/{$page->id}/authors", ['user_ids' => [$author->id]]);

    expect(Cache::has(config('app-cache.keys.navigation_tree')))->toBeFalse();
});
